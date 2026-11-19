<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_micro_video;

use app\common\enum\FileEnum;
use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\app\aigc_video\AigcVideoAssetService;
use app\common\service\FileService;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\storage\StorageConfigService;
use app\common\service\storage\StorageSignedUrlService;
use RuntimeException;
use think\facade\Db;

/** Media input authority comes from owned upload receipts, never browser storage fields. */
final class MicroVideoMedia
{
    private const MAX_IMAGE_BYTES = 10485760;
    private const MAX_VIDEO_BYTES = 268435456;

    public static function validateImages(int $tenant, int $user, array $images, int $max): array
    {
        if ($tenant <= 0 || $user <= 0 || $max < 1 || count($images) > $max) {
            throw new RuntimeException('参考图片数量或用户身份无效');
        }
        $result = [];
        foreach ($images as $image) {
            if (!is_array($image)) throw new RuntimeException('参考图片格式无效');
            $input = (string)($image['uri'] ?? $image['url'] ?? '');
            $uri = self::canonicalUri($input);
            $file = self::ownedFile($tenant, $user, $uri, FileEnum::IMAGE_TYPE);
            $storage = self::storage($file, $tenant);
            $url = self::managedUrl($uri, $storage, $tenant);
            // An absolute browser URL may identify an owned object only at its real origin.
            // Merely matching /uploads/... on an arbitrary host is never sufficient.
            if (preg_match('#^https?://#i', $input) && !self::sameObjectUrl($input, $url)
                && !($storage['storage_engine'] === 'local' && self::sameObjectUrl($input, FileService::format($storage['storage_domain'], $uri)))) {
                throw new RuntimeException('参考图片地址与所属素材不匹配');
            }
            if ($storage['storage_engine'] === 'local') self::imageData(self::localPath($uri));
            else OpenSafeHttp::resolve($url, false);
            $result[] = [
                'uri' => $uri, 'url' => $url,
                'name' => mb_substr((string)($image['name'] ?? $file['name'] ?? basename($uri)), 0, 200),
                'role' => mb_substr((string)($image['role'] ?? ''), 0, 100),
                // Persist these internal identities so a later worker can revalidate ownership.
                'tenant_id' => $tenant, 'user_id' => $user, 'file_id' => (int)$file['id'],
            ] + $storage;
        }
        return $result;
    }

    public static function visionInputs(array $canonicalImages): array
    {
        $result = [];
        foreach ($canonicalImages as $image) {
            if (!is_array($image)) throw new RuntimeException('参考图片格式无效');
            $tenant = (int)($image['tenant_id'] ?? 0); $user = (int)($image['user_id'] ?? 0);
            $fresh = self::validateImages($tenant, $user, [$image], 1)[0];
            foreach (['file_id', 'storage_scope', 'storage_engine', 'storage_domain'] as $key) {
                if ((string)($image[$key] ?? '') !== (string)$fresh[$key]) throw new RuntimeException('参考图片已变化，请重新选择');
            }
            $result[] = $fresh['storage_engine'] === 'local'
                ? self::imageData(self::localPath($fresh['uri'])) : $fresh['url'];
        }
        return $result;
    }

    /** Read the generated file without publishing another asset or changing its timing. */
    public static function measureDuration(int $tenant, int $user, string $uri): float
    {
        if ($tenant <= 0 || $user <= 0) throw new RuntimeException('视频片段身份无效');
        $workspace = self::workspace();
        try {
            $source = $workspace . '/source.mp4';
            if (!preg_match('#^(?:https?://|data:video/)#i', $uri)) {
                $canonical = self::canonicalUri($uri);
                $file = self::ownedFile($tenant, $user, $canonical, FileEnum::VIDEO_TYPE);
                $storage = self::storage($file, $tenant);
                if ($storage['storage_engine'] !== 'local') $uri = self::managedUrl($canonical, $storage, $tenant);
            }
            self::materialize($uri, $source, $tenant, $user);
            return self::probe($source)['duration'];
        } finally { self::cleanup($workspace); }
    }

    public static function persistSegment(int $tenant, int $user, string $uri, int $taskId, int $segmentId): array
    {
        if ($tenant <= 0 || $user <= 0 || $taskId <= 0 || $segmentId <= 0) throw new RuntimeException('视频片段身份无效');
        $workspace = self::workspace();
        try {
            $source = $workspace . '/source.mp4';
            self::materialize($uri, $source, $tenant, $user);
            $media = self::probe($source);
            $stored = self::persistVideo($source, $tenant, $user);
            return [
                'video_uri' => $stored['uri'], 'video_url' => self::managedUrl($stored['uri'], $stored, $tenant),
                'duration' => $media['duration'], 'width' => $media['width'], 'height' => $media['height'],
            ] + self::storageFields($stored);
        } finally { self::cleanup($workspace); }
    }

    public static function merge(array $task, array $segments): array
    {
        $tenant = (int)($task['tenant_id'] ?? 0); $user = (int)($task['user_id'] ?? 0);
        if ($tenant <= 0 || $user <= 0 || !$segments) throw new RuntimeException('待合并视频片段无效');
        usort($segments, static fn(array $a, array $b): int => (int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0));
        $workspace = self::workspace();
        try {
            $inputs = []; $sharedBoundaries = [];
            foreach ($segments as $index => $segment) {
                if (isset($segment['status']) && $segment['status'] !== 'success') throw new RuntimeException('仅成功片段可以合并');
                if (isset($segment['task_id']) && (int)$segment['task_id'] !== (int)($task['id'] ?? 0)) throw new RuntimeException('视频片段不属于当前任务');
                $uri = self::canonicalUri((string)($segment['video_uri'] ?? ''));
                $file = self::ownedFile($tenant, $user, $uri, FileEnum::VIDEO_TYPE);
                $storage = self::storage($file, $tenant);
                foreach (array_keys(self::storageFields($storage)) as $key) {
                    if (isset($segment[$key]) && (string)$segment[$key] !== $storage[$key]) throw new RuntimeException('视频片段存储信息已变化');
                }
                $source = $workspace . '/source-' . $index . '.mp4';
                self::materialize($storage['storage_engine'] === 'local' ? $uri : self::managedUrl($uri, $storage, $tenant), $source, $tenant, $user);
                $inputs[] = $source;
                if ($index > 0 && ($task['generation_mode'] ?? '') === 'start_end') {
                    $anchor = (int)($segment['first_frame_id'] ?? 0);
                    $sharedBoundaries[$index] = $anchor > 0 && $anchor === (int)($segments[$index - 1]['last_frame_id'] ?? 0);
                }
            }
            $shots = MicroVideoStore::json($task['params_json'] ?? [])['shots'] ?? [];
            $mixedQualities = count(array_unique(array_column($shots, 'quality'))) > 1;
            $merged = self::concatLocal($inputs, $workspace, $sharedBoundaries, $mixedQualities);
            $poster = $workspace . '/poster.jpg';
            self::run([self::binary('ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y', '-i', $merged['path'], '-frames:v', '1', '-q:v', '2', $poster], 90);
            if (!is_file($poster) || !@getimagesize($poster)) throw new RuntimeException('视频封面生成失败');
            // Publish only fully validated media. The caller marks the task successful after both uploads.
            $stored = self::persistVideo($merged['path'], $tenant, $user);
            $cover = AigcImageAssetService::persistGeneratedImage('data:image/jpeg;base64,' . base64_encode((string)file_get_contents($poster)), $tenant, $user);
            if (empty($cover['stored'])) throw new RuntimeException('视频封面持久化失败');
            return [
                'uri' => $stored['uri'], 'url' => self::managedUrl($stored['uri'], $stored, $tenant),
                'cover_uri' => $cover['uri'], 'cover_url' => self::managedUrl($cover['uri'], $cover, $tenant),
                'duration' => $merged['duration'], 'width' => $merged['width'], 'height' => $merged['height'], 'has_audio' => $merged['source_has_audio'],
            ] + self::storageFields($stored);
        } finally { self::cleanup($workspace); }
    }

    public static function subtitlePreflight(): void
    {
        self::binary('ffprobe'); self::subtitleFont();
        if (!preg_match('/\bsubtitles\s+V->V/', self::run([self::binary('ffmpeg'), '-hide_banner', '-filters'], 15))) {
            throw new RuntimeException('视频处理程序缺少字幕滤镜，请安装支持 libass 的 FFmpeg');
        }
    }

    private static function subtitleFont(): string
    {
        $font = getenv('MICRO_VIDEO_SUBTITLE_FONT') ?: '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc';
        if (!is_file($font) || !is_readable($font)) throw new RuntimeException('未找到中文字幕字体，请安装 fonts-noto-cjk');
        return $font;
    }

    public static function subtitleMovie(array $task, array $original, callable $recognize, callable $finished, bool $extractAudio = true): array
    {
        self::subtitlePreflight();
        $tenant=(int)$task['tenant_id'];$user=(int)$task['user_id'];$workspace=self::workspace();
        try {
            $uri=self::canonicalUri($original['uri']);$file=self::ownedFile($tenant,$user,$uri,FileEnum::VIDEO_TYPE);$storage=self::storage($file,$tenant);
            $input=$workspace.'/original.mp4';
            self::materialize($storage['storage_engine']==='local'?$uri:self::managedUrl($uri,$storage,$tenant),$input,$tenant,$user);
            $probe=self::probe($input);$audio=$workspace.'/speech.wav';
            if($extractAudio)self::extractSubtitleAudio($input,$audio);
            $cues=$recognize($audio);
            $result=$original;
            if($cues){
                $path=self::burnSubtitles($input,$workspace,$cues,$probe);
                $stored=self::persistVideo($path,$tenant,$user);
                $result=array_replace($original,['uri'=>$stored['uri'],'url'=>self::managedUrl($stored['uri'],$stored,$tenant)],self::storageFields($stored));
            }
            $finished($result);return $result;
        } finally { self::cleanup($workspace); }
    }

    private static function extractSubtitleAudio(string $input,string $output): void
    {
        self::run([self::binary('ffmpeg'),'-v','error','-nostdin','-y','-protocol_whitelist','file','-i',$input,'-vn','-map','0:a:0','-ac','1','-ar','16000','-c:a','pcm_s16le','-threads','1',$output],180);
        if(!is_file($output)||filesize($output)<=44||filesize($output)>100*1024*1024)throw new RuntimeException('字幕音轨提取失败或超过大小限制');
    }

    private static function burnSubtitles(string $input,string $workspace,array $cues,array $probe): string
    {
        $font=self::subtitleFont();$family=getenv('MICRO_VIDEO_SUBTITLE_FONT_FAMILY')?:'Noto Sans CJK SC';
        if(preg_match('/[,\r\n]/',$family))throw new RuntimeException('字幕字体名称无效');
        if(!copy($font,$workspace.'/subtitle-font.'.pathinfo($font,PATHINFO_EXTENSION)))throw new RuntimeException('字幕字体读取失败');
        $ass=MicroVideoSubtitles::ass($cues,$probe['width'],$probe['height'],$family);
        if(file_put_contents($workspace.'/subtitles.ass',$ass)===false)throw new RuntimeException('字幕文件写入失败');
        $output=$workspace.'/subtitled.mp4';
        self::run([self::binary('ffmpeg'),'-v','error','-nostdin','-y','-protocol_whitelist','file','-i',$input,'-map','0:v:0','-map','0:a:0?','-vf','subtitles=filename=subtitles.ass:fontsdir=.','-c:v','libx264','-preset','veryfast','-crf','18','-pix_fmt','yuv420p','-c:a','copy','-movflags','+faststart',$output],900,$workspace);
        $final=self::probe($output);
        if(abs($final['duration']-$probe['duration'])>0.3||$final['width']!==$probe['width']||$final['height']!==$probe['height'])throw new RuntimeException('字幕成片校验失败');
        return $output;
    }

    public static function extractRoleVoice(int $tenant,int $user,string $uri): array
    {
        $workspace=self::workspace();
        try {
            $source=$workspace.'/voice-source.mp4';self::materialize($uri,$source,$tenant,$user);
            $media=self::probe($source);
            if(!$media['has_audio']||$media['duration']<1||$media['duration']>16)throw new RuntimeException('NO_AUDIO');
            $target=$workspace.'/voice.wav';
            self::run([self::binary('ffmpeg'),'-v','error','-y','-protocol_whitelist','file','-i',$source,'-map','0:a:0','-vn','-ac','1','-ar','24000','-c:a','pcm_s16le',$target],90);
            $audio=self::compactRoleVoice((string)file_get_contents($target));$bytes=$audio['bytes'];
            $stored=\app\common\service\app\aigc_music\AigcMusicAssetService::persistGeneratedAudio('data:audio/wav;base64,'.base64_encode($bytes),$tenant,$user);
            if(empty($stored['stored'])||empty($stored['uri']))throw new RuntimeException('角色声音保存失败');
            return ['uri'=>$stored['uri'],'url'=>self::managedUrl($stored['uri'],$stored,$tenant),'duration'=>$audio['duration']];
        } finally {self::cleanup($workspace);}
    }

    /** Evaluate the native model result: validate metadata only and persist the exact returned bytes. */
    public static function persistRoleAudition(int $tenant,int $user,string $wav): array
    {
        if(strlen($wav)>30*1024*1024||substr($wav,0,4)!=='RIFF'||substr($wav,8,4)!=='WAVE')throw new RuntimeException('NO_AUDIO');
        $workspace=self::workspace();
        try {
            $source=$workspace.'/raw.wav';
            file_put_contents($source,$wav);
            $info=json_decode(self::run([self::binary('ffprobe'),'-v','error','-protocol_whitelist','file','-format_whitelist','wav','-show_streams','-show_format','-of','json',$source],30),true,512,JSON_THROW_ON_ERROR);
            $duration=(float)($info['format']['duration']??0);
            if(!is_finite($duration)||$duration<=0||!array_filter($info['streams']??[],static fn($s)=>($s['codec_type']??'')==='audio'))throw new RuntimeException('NO_AUDIO');
            // Do not trim silence, normalize, change channels, resample, or stretch the generated audio.
            $stored=\app\common\service\app\aigc_music\AigcMusicAssetService::persistGeneratedAudio('data:audio/wav;base64,'.base64_encode($wav),$tenant,$user);
            if(empty($stored['stored'])||empty($stored['uri']))throw new RuntimeException('角色声音保存失败');
            return ['uri'=>$stored['uri'],'url'=>self::managedUrl($stored['uri'],$stored,$tenant),'duration'=>$duration,'audio_sha256'=>hash('sha256',$wav)];
        } finally {self::cleanup($workspace);}
    }

    /** Keep the complete spoken interval, including pauses, with a small lead-in and natural tail. */
    private static function compactRoleVoice(string $wav): array
    {
        if(strlen($wav)<44||strlen($wav)>15*1024*1024||substr($wav,0,4)!=='RIFF'||substr($wav,8,4)!=='WAVE')throw new RuntimeException('NO_AUDIO');
        $pcm='';$format=null;
        for($offset=12;$offset+8<=strlen($wav);){
            $kind=substr($wav,$offset,4);$size=unpack('V',substr($wav,$offset+4,4))[1];$offset+=8;
            if($offset+$size>strlen($wav))throw new RuntimeException('NO_AUDIO');
            if($kind==='fmt '&&$size>=16)$format=unpack('vencoding/vchannels/Vrate/VbytesPerSecond/vblock/vbits',substr($wav,$offset,16));
            if($kind==='data')$pcm=substr($wav,$offset,$size);
            $offset+=$size+($size%2);
        }
        if(!$format||$format['encoding']!==1||$format['channels']!==1||$format['rate']!==24000||$format['bits']!==16||strlen($pcm)%2)throw new RuntimeException('NO_AUDIO');
        $frames=intdiv(strlen($pcm),2);$first=null;$last=null;
        for($i=0;$i<$frames;$i++){
            $sample=ord($pcm[$i*2])|(ord($pcm[$i*2+1])<<8);if($sample>32767)$sample-=65536;
            if(abs($sample)>=32){$first??=$i;$last=$i;}
        }
        if($first===null)throw new RuntimeException('NO_AUDIO');
        $start=max(0,$first-3600);$end=min($frames,$last+1+6000);
        // Downstream voice references require at least one second. Never remove sentence-internal pauses.
        if($end-$start<24000){$end=min($frames,$start+24000);$start=max(0,$end-24000);}
        $pcm=substr($pcm,$start*2,($end-$start)*2);$size=strlen($pcm);
        if(($end-$start)/24000>15.1)throw new RuntimeException('试音超过15秒，请缩短台词');
        $bytes='RIFF'.pack('V',36+$size).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',$size).$pcm;
        return ['bytes'=>$bytes,'duration'=>($end-$start)/24000];
    }

    private static function canonicalUri(string $input): string
    {
        if ($input === '' || str_contains($input, '\\') || preg_match('/[\x00-\x20\x7f]/', $input)) throw new RuntimeException('素材路径无效');
        $parts = parse_url($input);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true))
            || (!isset($parts['scheme']) && isset($parts['host']))) throw new RuntimeException('素材路径无效');
        $path = (string)($parts['path'] ?? '');
        for ($i = 0; $i < 3; $i++) { $decoded = rawurldecode($path); if ($decoded === $path) break; $path = $decoded; }
        $path = ltrim($path, '/');
        if (!str_starts_with($path, 'uploads/') || preg_match('#(^|/)\.{1,2}(/|$)#', $path)
            || str_contains($path, '\\') || str_contains($path, '%') || preg_match('/[\x00-\x20\x7f?#]/', $path)) throw new RuntimeException('素材路径无效');
        return $path;
    }

    private static function ownedFile(int $tenant, int $user, string $uri, int $type): array
    {
        if ($tenant <= 0 || $user <= 0) throw new RuntimeException('素材所属身份无效');
        $row = Db::name('tenant_file')->where(['tenant_id' => $tenant, 'source' => FileEnum::SOURCE_USER, 'source_id' => $user, 'type' => $type, 'uri' => $uri])
            ->whereRaw('(delete_time IS NULL OR delete_time = 0)')->order('id', 'desc')->find();
        self::assertOwnedFile($row ?: [], $tenant, $user, $uri, $type);
        return $row;
    }

    private static function assertOwnedFile(array $row, int $tenant, int $user, string $uri, int $type): void
    {
        if (!$row || (int)($row['tenant_id'] ?? 0) !== $tenant || (int)($row['source_id'] ?? 0) !== $user
            || (int)($row['source'] ?? -1) !== FileEnum::SOURCE_USER || (int)($row['type'] ?? 0) !== $type
            || (string)($row['uri'] ?? '') !== $uri || !empty($row['delete_time'])) throw new RuntimeException('素材不存在或不属于当前用户');
    }

    private static function storage(array $row, int $tenant): array
    {
        $storage = self::storageFields($row);
        $config = StorageConfigService::getStoredFileConfig($tenant, $storage['storage_scope'], $storage['storage_engine']);
        if ($storage['storage_scope'] === '') $storage['storage_scope'] = (string)$config['scope'];
        if ($storage['storage_engine'] === '') $storage['storage_engine'] = (string)$config['default'];
        // A local legacy row can have no domain. Do not freeze a request-dependent
        // host into its identity: a queue worker has a different HTTP context.
        if ($storage['storage_domain'] === '' && $storage['storage_engine'] !== 'local') $storage['storage_domain'] = StorageConfigService::getStorageDomain($config);
        if (!in_array($storage['storage_scope'], ['platform', 'tenant'], true) || !in_array($storage['storage_engine'], ['local', 'aliyun', 'qcloud', 'qiniu'], true)) throw new RuntimeException('素材存储配置无效');
        return $storage;
    }

    private static function storageFields(array $row): array
    {
        return ['storage_scope' => (string)($row['storage_scope'] ?? ''), 'storage_engine' => (string)($row['storage_engine'] ?? ''), 'storage_domain' => (string)($row['storage_domain'] ?? '')];
    }

    private static function managedUrl(string $uri, array $storage, int $tenant): string
    {
        if (($storage['storage_engine'] ?? '') === 'local') {
            $domain = (string)request()->domain();
            if (!parse_url($domain, PHP_URL_HOST)) $domain = (string)($storage['storage_domain'] ?? '');
            // CLI workers can have no host at all. A managed relative URI remains
            // correct until the authenticated read endpoint resolves the URL.
            return parse_url($domain, PHP_URL_HOST) ? FileService::format($domain, $uri) : '/' . ltrim($uri, '/');
        }
        $signed = StorageSignedUrlService::resolve($uri, (string)($storage['storage_scope'] ?? ''), (string)($storage['storage_engine'] ?? ''), (string)($storage['storage_domain'] ?? ''), $tenant);
        $url = $signed ?? FileService::format((string)($storage['storage_domain'] ?? ''), $uri);
        OpenSafeHttp::resolve($url, false);
        return $url;
    }

    private static function sameObjectUrl(string $a, string $b): bool
    {
        $left = parse_url($a); $right = parse_url($b);
        if (!is_array($left) || !is_array($right)) return false;
        foreach (['scheme', 'host', 'port'] as $key) if (strtolower((string)($left[$key] ?? '')) !== strtolower((string)($right[$key] ?? ''))) return false;
        return rawurldecode((string)($left['path'] ?? '')) === rawurldecode((string)($right['path'] ?? ''));
    }

    private static function localPath(string $uri): string
    {
        $root = realpath(dirname(__DIR__, 5) . '/public/uploads');
        $path = realpath(dirname(__DIR__, 5) . '/public/' . self::canonicalUri($uri));
        if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) throw new RuntimeException('本地素材不可用');
        return $path;
    }

    private static function imageData(string $path): string
    {
        if (!is_file($path) || filesize($path) <= 0 || filesize($path) > self::MAX_IMAGE_BYTES) throw new RuntimeException('参考图片不可用或超过10MB');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !@getimagesize($path)) throw new RuntimeException('仅支持有效的JPEG、PNG或WebP图片');
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('参考图片读取失败');
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    private static function materialize(string $uri, string $target, int $tenant, int $user): void
    {
        if (str_starts_with($uri, 'data:video/')) {
            if (strlen($uri) > (int)(self::MAX_VIDEO_BYTES * 1.4) || !preg_match('#^data:video/[a-zA-Z0-9.+-]+;base64,(.+)$#s', $uri, $match)) throw new RuntimeException('视频数据无效或过大');
            $bytes = base64_decode($match[1], true);
            if ($bytes === false) throw new RuntimeException('视频数据解析失败');
        } elseif (preg_match('#^https?://#i', $uri)) {
            // Some runtimes return an already managed local result as an absolute
            // web URL. Resolve only an exact owned origin/object before applying
            // the public-network downloader; never fetch a private network URL.
            $local = null;
            try {
                $canonical = self::canonicalUri($uri);
                $file = self::ownedFile($tenant, $user, $canonical, FileEnum::VIDEO_TYPE);
                $storage = self::storage($file, $tenant);
                if ($storage['storage_engine'] === 'local' && (self::sameObjectUrl($uri, self::managedUrl($canonical, $storage, $tenant))
                    || self::sameObjectUrl($uri, FileService::format($storage['storage_domain'], $canonical)))) $local = self::localPath($canonical);
            } catch (RuntimeException) { /* Unmanaged provider URLs continue through safe public HTTP. */ }
            if ($local !== null) {
                if (filesize($local) > self::MAX_VIDEO_BYTES || !copy($local, $target)) throw new RuntimeException('视频片段读取失败或过大');
                return;
            }
            $response = OpenSafeHttp::get($uri, self::MAX_VIDEO_BYTES, 120);
            if ((int)$response['status'] < 200 || (int)$response['status'] >= 300) throw new RuntimeException('视频下载失败');
            $bytes = $response['body'];
        } else {
            $canonical = self::canonicalUri($uri);
            self::ownedFile($tenant, $user, $canonical, FileEnum::VIDEO_TYPE);
            $path = self::localPath($canonical);
            if (filesize($path) > self::MAX_VIDEO_BYTES || !copy($path, $target)) throw new RuntimeException('视频片段读取失败或过大');
            return;
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_VIDEO_BYTES || file_put_contents($target, $bytes) !== strlen($bytes)) throw new RuntimeException('视频片段下载失败或过大');
    }

    private static function persistVideo(string $path, int $tenant, int $user): array
    {
        if (filesize($path) > self::MAX_VIDEO_BYTES) throw new RuntimeException('视频文件超过存储限制');
        $stored = AigcVideoAssetService::persistGeneratedVideoFile($path, $tenant, $user);
        if (empty($stored['stored']) || empty($stored['uri'])) throw new RuntimeException('视频持久化失败');
        return $stored;
    }

    /** Only shared-anchor repeated prefixes may be removed. Never speed up or pad footage. */
    private static function concatLocal(array $inputs, string $workspace, array $sharedBoundaries = [], bool $useLargestCanvas = false): array
    {
        if (!$inputs) throw new RuntimeException('没有可合并的视频片段');
        $ffmpeg = self::binary('ffmpeg'); $normalized = []; $expected = 0.0; $removed = 0.0; $sourceHasAudio = false;
        $probes = array_map([self::class, 'probe'], $inputs);
        $canvas = $probes[0];
        if ($useLargestCanvas) {
            foreach ($probes as $probe) {
                if ($probe['width'] * $probe['height'] > $canvas['width'] * $canvas['height']) $canvas = $probe;
            }
        }
        $width = max(2, (int)(round($canvas['width'] / 2) * 2));
        $height = max(2, (int)(round($canvas['height'] / 2) * 2));
        $scale = 'scale=' . $width . ':' . $height . ':flags=lanczos';
        if ($useLargestCanvas) {
            $scale .= ':force_original_aspect_ratio=decrease:force_divisible_by=2,pad=' . $width . ':' . $height . ':(ow-iw)/2:(oh-ih)/2';
        }
        foreach ($inputs as $index => $input) {
            $probe = $probes[$index];
            $sourceHasAudio = $sourceHasAudio || $probe['has_audio'];
            $trimFrames = $index > 0 && !empty($sharedBoundaries[$index]) ? self::repeatedBoundaryFrames($inputs[$index - 1], $input) : 0;
            $trimSeconds = $trimFrames / 30; $removed += $trimSeconds; $expected += $probe['duration'] - $trimSeconds;
            $path = $workspace . '/normalized-' . $index . '.mp4';
            $command = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-i', $input];
            if (!$probe['has_audio']) {
                // A silent transport track preserves the next clip's audio start on a mixed timeline.
                $command = array_merge($command, ['-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo:d=' . sprintf('%.9F', $probe['video_duration'])]);
            }
            $command = array_merge($command, ['-map', '0:v:0', '-map', $probe['has_audio'] ? '0:a:0' : '1:a:0',
                '-vf', $scale . ',setsar=1,fps=30' . ($trimFrames ? ',trim=start_frame=' . $trimFrames : '') . ',setpts=PTS-STARTPTS',
                '-af', ($trimFrames ? 'atrim=start=' . sprintf('%.9F', $trimSeconds) . ',' : '') . 'aresample=48000:async=0,asetpts=PTS-STARTPTS', '-r', '30', '-fps_mode', 'cfr',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p', '-video_track_timescale', '90000',
                '-c:a', 'aac', '-b:a', '192k', '-ar', '48000', '-ac', '2', '-movflags', '+faststart', $path]);
            self::run($command, 900);
            self::probe($path);
            $normalized[] = "file '" . basename($path) . "'";
        }
        $list = $workspace . '/concat.txt';
        if (file_put_contents($list, implode("\n", $normalized) . "\n") === false) throw new RuntimeException('合并清单写入失败');
        $partial = $workspace . '/merged.partial.mp4';
        self::run([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'concat', '-safe', '1', '-i', $list, '-map', '0:v:0', '-map', '0:a:0', '-c', 'copy', '-movflags', '+faststart', $partial], 900);
        $final = self::probe($partial);
        if (!str_contains($final['format'], 'mp4') || abs($final['duration'] - $expected) > max(0.3, count($inputs) * 0.12)) throw new RuntimeException('合并视频时长校验失败');
        $path = $workspace . '/merged.mp4';
        if (!rename($partial, $path)) throw new RuntimeException('合并视频发布失败');
        return ['path' => $path, 'deduplicated_duration' => $removed, 'source_has_audio' => $sourceHasAudio] + $final;
    }

    private static function repeatedBoundaryFrames(string $previous, string $next): int
    {
        // Inspect only 0.25 seconds at the join, never deduplicate the whole movie.
        $before = self::probe($previous); $after = self::probe($next);
        if ($before['video_duration'] < 0.5 || $after['video_duration'] < 0.5) return 0;
        $ffmpeg = self::binary('ffmpeg'); $filter = 'scale=96:54:flags=area,format=gray,fps=30'; $bytes = 96 * 54;
        $tail = self::run([$ffmpeg, '-v', 'error', '-ss', sprintf('%.9F', max(0, $before['video_duration'] - 0.5)), '-i', $previous, '-an', '-vf', $filter, '-f', 'rawvideo', 'pipe:1'], 60);
        $head = self::run([$ffmpeg, '-v', 'error', '-i', $next, '-t', '0.25', '-an', '-vf', $filter, '-f', 'rawvideo', 'pipe:1'], 60);
        if (strlen($tail) < $bytes || strlen($tail) % $bytes !== 0 || strlen($head) % $bytes !== 0) throw new RuntimeException('衔接画面检查失败');
        $last = substr($tail, -$bytes); $count = 0;
        for ($frame = 0; $frame < min(7, intdiv(strlen($head), $bytes)); $frame++) {
            $difference = 0; $changed = 0; $offset = $frame * $bytes;
            for ($pixel = 0; $pixel < $bytes; $pixel++) { $delta = abs(ord($last[$pixel]) - ord($head[$offset + $pixel])); $difference += $delta; if ($delta > 8) $changed++; }
            if ($difference / $bytes > 2 || $changed / $bytes > 0.01) break;
            $count++;
        }
        return $count;
    }

    private static function probe(string $path): array
    {
        $output = self::run([self::binary('ffprobe'), '-v', 'error', '-protocol_whitelist', 'file', '-format_whitelist', 'mov,matroska,webm,avi', '-show_streams', '-show_format', '-of', 'json', $path], 60);
        $info = json_decode($output, true, 512, JSON_THROW_ON_ERROR); $video = null; $hasAudio = false;
        foreach ($info['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video' && empty($stream['disposition']['attached_pic']) && $video === null) $video = $stream;
            if (($stream['codec_type'] ?? '') === 'audio') $hasAudio = true;
        }
        $duration = (float)($info['format']['duration'] ?? $video['duration'] ?? 0);
        if (!$video || (int)($video['width'] ?? 0) <= 0 || (int)($video['height'] ?? 0) <= 0 || !is_finite($duration) || $duration <= 0) throw new RuntimeException('视频文件损坏或没有有效视频轨道');
        return ['duration' => $duration, 'video_duration' => (float)($video['duration'] ?? $duration), 'width' => (int)$video['width'], 'height' => (int)$video['height'], 'has_audio' => $hasAudio, 'format' => (string)($info['format']['format_name'] ?? '')];
    }

    private static function binary(string $name): string
    {
        $configured = getenv('MICRO_VIDEO_' . strtoupper($name));
        $candidates = $configured ? [$configured] : [];
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $directory) if ($directory !== '') $candidates[] = rtrim($directory, '/') . '/' . $name;
        $candidates = array_merge($candidates, ['/opt/homebrew/bin/' . $name, '/usr/bin/' . $name, '/usr/local/bin/' . $name]);
        foreach ($candidates as $candidate) if (is_file($candidate) && is_executable($candidate)) return $candidate;
        throw new RuntimeException('未找到视频处理程序：' . $name);
    }

    private static function run(array $command, int $timeout, ?string $cwd = null): string
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('视频处理程序启动失败');
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $started = microtime(true); $output = ''; $error = ''; $code = -1;
        try {
            do {
                $output .= (string)stream_get_contents($pipes[1]); $error .= (string)stream_get_contents($pipes[2]);
                if (strlen($output) > 2097152 || strlen($error) > 262144) throw new RuntimeException('视频处理输出异常');
                $status = proc_get_status($process);
                if (!$status['running']) { $code = (int)$status['exitcode']; break; }
                if (microtime(true) - $started > $timeout) throw new RuntimeException('视频处理超时');
                usleep(20000);
            } while (true);
            $output .= (string)stream_get_contents($pipes[1]); $error .= (string)stream_get_contents($pipes[2]);
        } finally {
            if ($code < 0) { proc_terminate($process); usleep(100000); $status = proc_get_status($process); if ($status['running']) proc_terminate($process, 9); }
            fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($process); if ($code < 0) $code = $closed;
        }
        if ($code !== 0) throw new RuntimeException('视频处理失败：' . mb_substr(trim($error), 0, 1000));
        return $output;
    }

    private static function workspace(): string
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/micro-video-' . bin2hex(random_bytes(16));
        if (!mkdir($path, 0700)) throw new RuntimeException('视频临时目录创建失败');
        return $path;
    }

    private static function cleanup(string $workspace): void
    {
        foreach (glob($workspace . '/*') ?: [] as $file) if (is_file($file) || is_link($file)) @unlink($file);
        @rmdir($workspace);
    }
}
