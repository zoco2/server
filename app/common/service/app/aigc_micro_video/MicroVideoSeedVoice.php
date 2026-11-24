<?php
namespace app\common\service\app\aigc_micro_video;

use app\common\model\TenantConfig;
use app\common\service\smart_edit\SmartEditVoiceConfigService;
use app\common\service\open_platform\OpenSafeHttp;
use think\facade\Db;
use InvalidArgumentException;
use RuntimeException;

/** Explicit account trial only: provider usage is recorded, no TP rate is invented. */
final class MicroVideoSeedVoice
{
    public const MODEL = 'seed-audio-1.0';
    public const TEXT = '我曾在银色平原漫步，也曾在青草之河垂钓';
    public const SECONDS = 4;
    public const MAX_TEXT = 80;
    public const STYLE = '真人对白质感，自然生活化口语，语速适中，咬字清晰，气息连贯，情绪克制，音色温润真实，音量平稳，无电子机械感。';

    public static function enabled(int $tenant, int $user): bool
    {
        $raw=TenantConfig::where(['tenant_id'=>$tenant,'type'=>'aigc_micro_video','name'=>'seed_audio_trial'])->value('value');
        $config=is_string($raw)?json_decode($raw,true):[];
        return $user>0 && !empty($config['enabled']) && in_array($user,array_map('intval',(array)($config['user_ids']??[])),true);
    }
    public static function requireTrial(int $tenant,int $user): void
    {
        if(!self::enabled($tenant,$user))throw new InvalidArgumentException('当前账号未开放 Seed-Audio 测试');
        SmartEditVoiceConfigService::requirePlatform();
    }
    public static function config(int $tenant,int $user): array
    {
        MicroVideoService::available($tenant);
        if(!self::enabled($tenant,$user))return ['enabled'=>false];
        self::requireTrial($tenant,$user);
        return ['enabled'=>true,'trial'=>true,'default_text'=>self::TEXT,'option_config'=>['channels'=>[
            ['id'=>self::MODEL,'value'=>self::MODEL,'name'=>'Seed-Audio 1.0','app_code'=>'seed_audio','enabled'=>true,'available'=>true,'max_reference_images'=>1,'durations'=>[self::SECONDS]]
        ]]];
    }
    public static function quote(int $tenant,int $user): array
    {
        MicroVideoService::available($tenant);self::requireTrial($tenant,$user);
        return ['user_charge_points'=>0,'trial'=>true,'duration'=>self::SECONDS,'model_id'=>self::MODEL];
    }
    public static function prompt(string $text,string $direction=''): string
    {
        $parts=['最高优先级：只生成单人的纯净人声干声。全程禁止任何背景音乐、伴奏、环境音和音效，无混响、无回声。停顿时保持安静，台词读完立即结束。',
            '严格参考图片里人物的年龄、性别、神态气质生成人声。'.self::STYLE];
        if($direction!=='')$parts[]='补充声音要求：'.$direction;
        $parts[]='只朗读以下台词一次，目标在'.self::SECONDS.'秒内完整读完，逗号处短暂停顿，不增字、不漏字、不重复：';
        $parts[]=$text;
        return implode("\n\n",$parts);
    }
    public static function prepare(int $tenant,int $user,array $voice): array
    {
        self::requireTrial($tenant,$user);
        $text=trim((string)($voice['text']??self::TEXT));$direction=trim((string)($voice['direction']??''));
        if($text===''||mb_strlen($text)>self::MAX_TEXT||mb_strlen($direction)>300)throw new InvalidArgumentException('请填写80字以内的试音台词');
        $p=(array)($voice['request']??[]);
        if((string)($p['duration']??'')!==(string)self::SECONDS||!isset($p['expected_points'])||!is_numeric($p['expected_points'])||(float)$p['expected_points']!==0.0)throw new InvalidArgumentException('声音设置已变化，请刷新后重试');
        return ['text'=>$text,'direction'=>$direction,'request'=>['model_id'=>self::MODEL,'channel'=>self::MODEL,'duration'=>self::SECONDS,'quantity'=>1,'expected_points'=>0,'trial'=>true,'prompt'=>self::prompt($text,$direction)]];
    }
    private static function write(int $id,array $data): void
    { MicroVideoWorkbenchJobs::table()->where('id',$id)->update($data+['update_time'=>time()]); }
    private static function textOnly(string $text): string
    { return preg_replace('/[\p{P}\p{Z}\s]+/u','',$text)??''; }

    /** The workbench lease/flock wraps this method. The saved submission marker fences uncertain calls. */
    public static function run(array $job,bool $reconcileOnly=false): void
    {
        $id=(int)$job['id'];$tenant=(int)$job['tenant_id'];$user=(int)$job['user_id'];
        $request=MicroVideoStore::json($job['request_json']);$receipt=MicroVideoStore::json($job['receipt_json']);
        $root=runtime_path().'micro_seed_voice/'.$tenant.'/'.$user.'/'.$id;
        $responseFile=$root.'/response.json';$submitted=false;
        try{
            self::requireTrial($tenant,$user);
            $record=MicroVideoWorkbenchRecords::owned($tenant,$user,'role',(int)$job['project_id']);
            $body=MicroVideoStore::json($record['body_json']);
            if((int)($body['voice_generation']['job_id']??0)!==$id)throw new RuntimeException('角色已切换声音任务');
            $voice=self::prepare($tenant,$user,$request);
            if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new RuntimeException('声音保存目录不可用');
            if(!is_file($responseFile)){
                if(!empty($receipt['submitted_at'])||$reconcileOnly||$job['status']!=='queued'){
                    self::write($id,['status'=>'unknown','error'=>'声音提交状态待确认，不会重复生成','next_run_at'=>time()+300]);return;
                }
                $image=MicroVideoMedia::validateImages($tenant,$user,[$request['image']],1)[0];
                $input=MicroVideoMedia::visionInputs([$image])[0];
                if(preg_match('#^data:image/[^;]+;base64,(.+)$#s',$input,$m))$bytes=base64_decode($m[1],true);
                else{$download=OpenSafeHttp::get($input,10*1024*1024,30);if((int)$download['status']!==200)throw new RuntimeException('原始形象读取失败');$bytes=$download['body'];}
                if(!is_string($bytes)||strlen($bytes)>10*1024*1024||!in_array((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes),['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('参考图片格式或大小不支持');
                $config=SmartEditVoiceConfigService::requirePlatform();
                $requestId='role-seed-'.$tenant.'-'.$user.'-'.$id;
                $payload=['model'=>self::MODEL,'text_prompt'=>$voice['request']['prompt'],'references'=>[['image_data'=>base64_encode($bytes)]],
                    'audio_config'=>['format'=>'wav','sample_rate'=>48000,'speech_rate'=>0,'pitch_rate'=>0,'loudness_rate'=>0,'enable_subtitle'=>true]];
                $receipt=['model'=>self::MODEL,'trial'=>true,'submitted_at'=>time(),'request_id'=>$requestId,'image_sha256'=>hash('sha256',$bytes),'requested_seconds'=>self::SECONDS];
                self::write($id,['status'=>'running','receipt_json'=>MicroVideoStore::encode($receipt)]);$submitted=true;
                $headers=[];$ch=curl_init('https://openspeech.bytedance.com/api/v3/tts/create');
                curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>240,
                    CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Api-Key: '.$config['api_key'],'X-Api-Request-Id: '.$requestId],
                    CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                    CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$headers):int{$p=explode(':',$line,2);if(count($p)===2)$headers[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);}]);
                $raw=curl_exec($ch);$errno=curl_errno($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
                if($errno)throw new RuntimeException('声音请求连接中断，受理状态待核对');
                $receipt['http_status']=$http;$receipt['provider_log_id']=$headers['x-tt-logid']??'';
                $result=json_decode((string)$raw,true);
                if($http!==200||!is_array($result)||empty($result['audio'])){
                    self::write($id,['status'=>'failed','receipt_json'=>MicroVideoStore::encode($receipt),'error'=>'Seed-Audio 未返回有效音频（HTTP '.$http.'）']);return;
                }
                if(strlen((string)$raw)>35*1024*1024)throw new RuntimeException('声音返回结果超过保存上限');
                if(file_put_contents($responseFile.'.tmp',$raw,LOCK_EX)===false||!rename($responseFile.'.tmp',$responseFile))throw new RuntimeException('声音返回结果保存失败');
            }
            $submitted=false;$result=json_decode((string)file_get_contents($responseFile),true);
            $audio=base64_decode((string)($result['audio']??''),true);
            if(!is_string($audio)||substr($audio,0,4)!=='RIFF'||substr($audio,8,4)!=='WAVE')throw new RuntimeException('NO_AUDIO');
            $receipt['original_duration']=(float)($result['original_duration']??$result['duration']??0);
            $receipt['provider_subtitle']=(string)($result['subtitle']['text']??'');
            $receipt['estimated_official_rmb']=round($receipt['original_duration']/60,6);
            $receipt['user_charge_points']=0;
            self::write($id,['receipt_json'=>MicroVideoStore::encode($receipt)]);
            if(self::textOnly($receipt['provider_subtitle'])!==self::textOnly($voice['text']))throw new RuntimeException('生成台词与试音文案不一致，原始结果已保留');
            if(empty($receipt['voice'])){
                self::write($id,['status'=>'running']);
                $saved=MicroVideoMedia::persistRoleAudition($tenant,$user,$audio);
                $receipt['voice']=$saved+['name'=>$request['name'].' · AI声音.wav','source'=>'generated','description'=>$voice['direction'],'text'=>$voice['text'],'model'=>'Seed-Audio 1.0','job_id'=>$id,'original_duration'=>$receipt['original_duration']];
                self::write($id,['receipt_json'=>MicroVideoStore::encode($receipt)]);
            }
            Db::transaction(function()use($tenant,$user,$job,$id,$receipt){
                $fresh=MicroVideoWorkbenchRecords::owned($tenant,$user,'role',(int)$job['project_id'],false,true,true);$body=MicroVideoStore::json($fresh['body_json']);
                if((int)($body['voice_generation']['job_id']??0)!==$id)throw new RuntimeException('角色声音任务已变更');
                $body['voice']=$receipt['voice'];$body['voice_generation']['status']='success';
                MicroVideoWorkbenchRecords::save($tenant,$user,['kind'=>'role','id'=>$fresh['id'],'revision'=>$fresh['revision'],'title'=>$fresh['title'],'body'=>$body]);
                self::write($id,['status'=>'success','result_json'=>MicroVideoStore::encode(['voice'=>$receipt['voice']]),'user_charge_points'=>0,'error'=>'']);
            });
        }catch(\Throwable $e){
            self::write($id,['status'=>$submitted?'unknown':'failed','error'=>mb_substr($e->getMessage(),0,300),'next_run_at'=>time()+300]);
        }
    }
}
