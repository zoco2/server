<?php

namespace app\common\service\smart_edit;

use Exception;
use OSS\OssClient;
use think\facade\Log;

/**
 * 标题字体库。
 *
 * IMS 的 Font 字段只认渲染服务上已安装的系统字体，官方没有公开清单，
 * 靠字体名不可控。因此这里统一走 FontURL：把字体文件放到租户自己的 OSS，
 * 提交 Timeline 时用完整地址，效果确定。
 *
 * 字体文件按需下载一次，落到 {bucket}/smart-edit-fonts/ 下，
 * 之后直接复用，不重复下载也不重复上传。
 */
class SmartEditFontService
{
    /** 字体在租户 OSS 里的存放目录 */
    private const FONT_DIR = 'smart-edit-fonts';

    /** 单文件下载上限（字节），防止异常地址把磁盘写满 */
    private const MAX_FONT_BYTES = 40 * 1024 * 1024;

    /**
     * 请求内同步下载的超时（秒）。
     *
     * 中文字体动辄 20MB 以上，从 GitHub 拉取经常超过 PHP 的 max_execution_time，
     * 会让提交接口一直挂住。请求路径上只给很短的机会，超时就退回系统字体名，
     * 真正的预热交给 `php think smart-edit:fonts` 在部署时完成。
     */
    private const REQUEST_DOWNLOAD_TIMEOUT = 8;

    /** 命令行预热时的下载超时（秒） */
    private const CLI_DOWNLOAD_TIMEOUT = 300;

    /**
     * 预置字体清单。
     *
     * 全部为开源且可商用的中文字体，下载地址取自各项目官方 release。
     * aliases 里的名字是 IMS 文档出现过、且该字体可能已被安装的系统字体名，
     * 下载失败时回退到系统字体名，至少不至于完全不可用。
     *
     * @return array<string, array{label:string,url:string,filename:string,aliases:array<int,string>,preview:string}>
     */
    public static function catalog(): array
    {
        return [
            'wenkai' => [
                'label' => '霞鹜文楷',
                'url' => 'https://github.com/lxgw/LxgwWenKai/releases/download/v1.530/LXGWWenKai-Regular.ttf',
                'filename' => 'LXGWWenKai-Regular.ttf',
                'aliases' => ['LXGWWenKai'],
                'preview' => '温润手写感，适合情感、生活类文案',
            ],
            'wenkai-medium' => [
                'label' => '霞鹜文楷 粗',
                'url' => 'https://github.com/lxgw/LxgwWenKai/releases/download/v1.530/LXGWWenKai-Medium.ttf',
                'filename' => 'LXGWWenKai-Medium.ttf',
                'aliases' => ['LXGWWenKai-Medium'],
                'preview' => '同上的加粗版，标题更醒目',
            ],
            'wenkai-screen' => [
                'label' => '霞鹜文楷 屏幕',
                'url' => 'https://github.com/lxgw/LxgwWenKai-Screen/releases/download/v1.520/LXGWWenKaiScreen.ttf',
                'filename' => 'LXGWWenKaiScreen.ttf',
                'aliases' => ['LXGWWenKaiScreen'],
                'preview' => '针对小字号优化，成片缩小时更清晰',
            ],
            'smiley' => [
                'label' => '得意黑',
                'url' => 'https://github.com/atelier-anchor/smiley-sans/releases/download/v2.0.1/smiley-sans-v2.0.1.zip',
                'filename' => 'SmileySans-Oblique.ttf',
                'aliases' => ['HappyZcool-2016'],
                'preview' => '倾斜粗黑，适合潮流、促销类标题',
            ],
            'noto-sans' => [
                'label' => '思源黑体',
                'url' => 'https://github.com/googlefonts/noto-cjk/raw/main/Sans/SubsetOTF/SC/NotoSansSC-Regular.otf',
                'filename' => 'NotoSansSC-Regular.otf',
                'aliases' => ['Noto Sans SC', 'SourceHanSansCN-Regular'],
                'preview' => '标准无衬线，通用性最强，正文标题都合适',
            ],
            'noto-serif' => [
                'label' => '思源宋体',
                'url' => 'https://github.com/googlefonts/noto-cjk/raw/main/Serif/SubsetOTF/SC/NotoSerifSC-Regular.otf',
                'filename' => 'NotoSerifSC-Regular.otf',
                'aliases' => ['Noto Serif SC', 'SourceHanSerifCN-Regular'],
                'preview' => '传统衬线，适合文化、品牌感文案',
            ],
        ];
    }

    /**
     * 前端下拉用的字体列表。
     *
     * 只返回元数据，不带签名地址的字体由 prepare() 在提交时按需准备，
     * 避免每次打开页面都触发下载。
     *
     * @return array<int, array{key:string,label:string,preview:string}>
     */
    public static function options(): array
    {
        $rows = [];
        foreach (self::catalog() as $key => $item) {
            $rows[] = [
                'key' => $key,
                'label' => $item['label'],
                'preview' => $item['preview'],
            ];
        }
        return $rows;
    }

    /**
     * 取得某个预置字体的可访问地址，必要时先下载并上传到租户 OSS。
     *
     * 返回空串表示该字体不可用，调用方应回退到系统字体名或不设字体。
     *
     * @param array  $config  SmartEditImsConfigService::require() 的结果
     * @param string $key     字体 key，见 catalog()
     * @param int    $timeout 下载超时秒数；请求路径用短超时，命令行预热用长超时
     */
    public static function prepare(array $config, string $key, int $timeout = self::REQUEST_DOWNLOAD_TIMEOUT): string
    {
        $font = self::catalog()[$key] ?? null;
        if ($font === null) {
            return '';
        }
        $objectKey = self::FONT_DIR . '/' . $font['filename'];
        try {
            $client = self::client($config);
            $bucket = (string)$config['bucket'];
            // 已存在就不再上传：同一租户的字体通常反复使用
            if ($client->doesObjectExist($bucket, $objectKey)) {
                return self::fontUrl($config, $client, $objectKey);
            }
            $local = self::download($font['url'], $font['filename'], $timeout);
            if ($local === '') {
                return '';
            }
            try {
                $client->uploadFile($bucket, $objectKey, $local, [
                    OssClient::OSS_CONTENT_TYPE => self::mimeOf($font['filename']),
                ]);
            } finally {
                @unlink($local);
            }
            return self::fontUrl($config, $client, $objectKey);
        } catch (\Throwable $e) {
            Log::write('[smart_edit] 字体准备失败 key=' . $key . ' ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 字体的回退系统字体名；下载失败时至少还能指定一个名字。
     */
    public static function aliasOf(string $key): string
    {
        return (string)(self::catalog()[$key]['aliases'][0] ?? '');
    }

    /**
     * 字体是否在预置清单中。
     */
    public static function has(string $key): bool
    {
        return isset(self::catalog()[$key]);
    }

    private static function client(array $config): OssClient
    {
        return new OssClient(
            (string)$config['access_key'],
            (string)$config['secret_key'],
            (string)$config['domain'],
            true
        );
    }

    private static function fontUrl(array $config, OssClient $client, string $objectKey): string
    {
        if (!empty($config['private_access'])) {
            // 字体同样排在他人的渲染队列里，有效期与素材保持一致
            return $client->signUrl(
                (string)$config['bucket'],
                $objectKey,
                21600,
                OssClient::OSS_HTTP_GET
            );
        }
        return SmartEditImsConfigService::joinUrl((string)$config['domain'], $objectKey);
    }

    /**
     * 下载字体到临时目录。
     *
     * 部分字体只提供 zip（如得意黑），这里解出其中的 ttf/otf。
     *
     * @param string $url      下载地址
     * @param string $filename 目标文件名
     * @param int    $timeout  本次下载允许的秒数
     * @return string 本地路径；失败返回空串
     */
    private static function download(string $url, string $filename, int $timeout = self::REQUEST_DOWNLOAD_TIMEOUT): string
    {
        $tmpDir = runtime_path() . 'smart_edit_fonts';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            return '';
        }
        $target = $tmpDir . DIRECTORY_SEPARATOR . $filename;
        // 已下载过的直接复用，避免重复走网络
        if (is_file($target) && filesize($target) > 0) {
            return $target;
        }

        $raw = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'font_' . md5($url);
        $fp = @fopen($raw, 'wb');
        if ($fp === false) {
            return '';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            // 连接和总时长都要限死：没有 CONNECTTIMEOUT 时，DNS 或握手卡住会吃掉整个超时
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'zoco-smart-edit/1.0',
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $status !== 200 || !is_file($raw) || filesize($raw) > self::MAX_FONT_BYTES) {
            @unlink($raw);
            return '';
        }

        // zip 包：解出第一个 ttf/otf
        if (str_ends_with(strtolower($filename), '.zip') || self::looksLikeZip($raw)) {
            $extracted = self::extractFromZip($raw, $tmpDir, $filename);
            @unlink($raw);
            if ($extracted === '') {
                return '';
            }
            if ($extracted !== $target) {
                @rename($extracted, $target);
            }
            return is_file($target) ? $target : '';
        }

        @rename($raw, $target);
        return is_file($target) ? $target : '';
    }

    private static function looksLikeZip(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return false;
        }
        $magic = fread($h, 2);
        fclose($h);
        return $magic === 'PK';
    }

    /**
     * 从 zip 中解出字体文件。
     *
     * @return string 解出的临时文件路径；失败返回空串
     */
    private static function extractFromZip(string $zipPath, string $tmpDir, string $wanted): string
    {
        if (!class_exists(\ZipArchive::class)) {
            Log::write('[smart_edit] 缺少 zip 扩展，无法解压字体包');
            return '';
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return '';
        }
        $found = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!preg_match('/\.(ttf|otf)$/i', $name)) {
                continue;
            }
            // 优先取与目标同名的文件，否则取第一个字体
            if ($found === '' || basename($name) === $wanted) {
                $found = $name;
            }
            if (basename($name) === $wanted) {
                break;
            }
        }
        if ($found === '') {
            $zip->close();
            return '';
        }
        $out = $tmpDir . DIRECTORY_SEPARATOR . 'extract_' . md5($found . microtime(true));
        $stream = $zip->getStream($found);
        if ($stream === false) {
            $zip->close();
            return '';
        }
        $fp = @fopen($out, 'wb');
        if ($fp === false) {
            fclose($stream);
            $zip->close();
            return '';
        }
        stream_copy_to_stream($stream, $fp);
        fclose($stream);
        fclose($fp);
        $zip->close();
        return is_file($out) ? $out : '';
    }

    private static function mimeOf(string $filename): string
    {
        return str_ends_with(strtolower($filename), '.otf')
            ? 'font/otf'
            : 'font/ttf';
    }
}
