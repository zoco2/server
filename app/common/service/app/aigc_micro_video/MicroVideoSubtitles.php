<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_micro_video;

use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use app\common\service\app\aigc_llm\agent\AgentTranscriptionTool;
use app\common\service\point\PointService;
use app\common\service\update\UpdateSourceClient;
use think\facade\Db;
use RuntimeException;

/** One transcription per finished movie. Existing attempts hold media and durable receipts. */
final class MicroVideoSubtitles
{
    public static function enabled(array $task): bool
    {
        return !empty(MicroVideoStore::json($task['params_json'])['subtitles_enabled']);
    }

    private static function attempt(int $task): ?array
    {
        return MicroVideoStore::table('attempt')->where(['task_id'=>$task,'kind'=>'subtitle'])->order('attempt_no','desc')->find();
    }

    public static function quote(array $task,bool $enabled): array
    {
        $attempt=$enabled?self::attempt((int)$task['id']):null;
        if(!$enabled||($attempt && $attempt['status']!=='failed' && ($attempt['status']==='success'||(int)$attempt['consumption_id']>0)))return [];
        // Use the same market SKU and tenant pricing as the existing STT integration.
        $quote=AgentTranscriptionTool::quote((int)$task['tenant_id']);
        $quote['tenant']=round($quote['tenant'],2);$quote['user']=round($quote['user'],2);
        return $quote;
    }

    private static function save(int $id,array $data,array $fields=[]): void
    {
        MicroVideoStore::table('attempt')->where('id',$id)->update($fields+['result_json'=>MicroVideoStore::encode($data),'update_time'=>time()]);
    }

    /** Only an explicit, freshly quoted continuation may repeat a rejected/refunded call. */
    public static function retry(array $task): void
    {
        if(!self::enabled($task))return;
        $prior=self::attempt((int)$task['id']);
        if(!$prior||$prior['status']!=='failed'||$prior['billing_status']!=='refunded')return;
        $data=MicroVideoStore::json($prior['result_json']);$now=time();
        MicroVideoStore::table('attempt')->insertGetId(['tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id'],'task_id'=>$task['id'],'segment_id'=>0,'kind'=>'subtitle','attempt_no'=>(int)$prior['attempt_no']+1,'status'=>'pending','request_json'=>'{}','result_json'=>MicroVideoStore::encode(['original'=>$data['original'],'stage'=>'extracting']),'create_time'=>$now,'update_time'=>$now]);
    }

    public static function process(array $task,array $segments): array
    {
        $attempt=self::attempt((int)$task['id']);
        $data=$attempt?MicroVideoStore::json($attempt['result_json']):[];
        if(isset($data['final']))return $data['final'];
        if(!$attempt){
            $now=time();
            $id=MicroVideoStore::table('attempt')->insertGetId(['tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id'],'task_id'=>$task['id'],'segment_id'=>0,'kind'=>'subtitle','attempt_no'=>1,'status'=>'pending','request_json'=>'{}','result_json'=>'{}','create_time'=>$now,'update_time'=>$now]);
            $attempt=self::attempt((int)$task['id']);
        }
        $id=(int)$attempt['id'];
        if(!isset($data['original'])){
            $data['original']=MicroVideoMedia::merge($task,$segments);
            $data['stage']='extracting';self::save($id,$data);
        }
        if(empty($data['original']['has_audio'])){
            $data['stage']='no_audio';$data['final']=$data['original'];self::save($id,$data,['status'=>'success']);return $data['final'];
        }
        return MicroVideoMedia::subtitleMovie($task,$data['original'],function(string $audio)use($task,$attempt,$id,&$data){
            $receipt=$data['receipt']??null;
            $journal=self::journal($id);
            if($receipt===null && is_file($journal)){
                $stored=MicroVideoStore::json(file_get_contents($journal));
                if(($stored['consumption_id']??0)!==(int)$attempt['consumption_id']||($stored['task_id']??0)!==(int)$task['id']||($stored['tenant_id']??0)!==(int)$task['tenant_id'])throw new RuntimeException('字幕回执归属不匹配，请核对');
                $receipt=$stored['receipt']??[];
            }
            if($receipt===null){
                if((int)$attempt['consumption_id']>0){self::uncertain((int)$attempt['consumption_id']);$data['stage']='needs_review';self::save($id,$data,['status'=>'unknown']);throw new RuntimeException('字幕识别回执待核对，已保留原片，请勿重复提交');}
                $source=UpdateSourceClient::getSource();
                $key=trim((string)($source['active_api_key']??$source['api_key']??$source['license_key']??''));
                $parts=parse_url((string)($source['active_base_url']??$source['base_url']??''));
                if(!$key||empty($parts['host']))throw new RuntimeException('语音识别服务未配置');
                $quote=MicroVideoStore::json($task['confirmed_quote'])['subtitles']??[];
                if(!$quote)throw new RuntimeException('字幕报价缺失，请重新估价');
                $data['stage']='transcribing';self::save($id,$data);
                $cid=self::reserve($task,$id,$quote);
                $url=($parts['scheme']??'https').'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'').'/api/v1/apps/voice_tts/stt';
                try{
                    $receipt=self::request($url,$key,$audio,$source);
                    // File journal is written before DB settlement, so recovery never repeats a paid call.
                    self::writeJournal($journal,['consumption_id'=>$cid,'task_id'=>(int)$task['id'],'tenant_id'=>(int)$task['tenant_id'],'receipt'=>$receipt]);
                }catch(\Throwable $e){self::uncertain($cid);throw $e;}
            }else $cid=(int)$attempt['consumption_id'];
            $data['receipt']=$receipt;self::save($id,$data);
            AiConsumptionLog::where('id',$cid)->update(['response_summary'=>MicroVideoStore::encode($receipt),'upstream_request_id'=>substr((string)($receipt['body']['data']['result']['request_id']??''),0,100),'update_time'=>time()]);
            $result=self::classify($receipt);
            if($result===null){
                self::uncertain($cid);
                $data['stage']='needs_review';self::save($id,$data,['status'=>'unknown']);
                throw new RuntimeException('字幕识别回执待核对，已保留原片，请勿重复提交');
            }
            self::settle($cid,$result['success'],$receipt);
            if(!$result['success']){
                $data['stage']='failed';self::save($id,$data,['status'=>'failed','billing_status'=>'refunded']);
                throw new RuntimeException('字幕识别失败，费用已退回，原片已保留');
            }
            try{$cues=self::cues($result['result'],(float)$data['original']['duration']);}
            catch(RuntimeException $e){$data['stage']='invalid_timestamps';self::save($id,$data,['billing_status'=>'settled']);throw $e;}
            $data['stage']=$cues?'rendering':'no_speech';
            self::save($id,$data,['billing_status'=>'settled']);
            return $cues;
        },function(array $final)use($id,&$data){
            if($data['stage']==='rendering')$data['stage']='success';
            $data['final']=$final;self::save($id,$data,['status'=>'success']);
        },!isset($data['receipt']) && !(int)$attempt['consumption_id']);
    }

    private static function reserve(array $task,int $attempt,array $quote): int
    {
        return Db::transaction(function()use($task,$attempt,$quote){
            $row=MicroVideoStore::table('attempt')->where('id',$attempt)->lock(true)->find();
            if((int)$row['consumption_id'])throw new RuntimeException('字幕识别已提交，请核对已有回执');
            $tenant=(int)$task['tenant_id'];$user=(int)$task['user_id'];$now=time();$no='MVSTT'.bin2hex(random_bytes(12));
            PointService::assertCanConsumeAmounts($tenant,$user,$quote['tenant'],$quote['user']);
            $app=AiAppTask::create(['task_no'=>$no,'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>MicroVideoStore::APP,'action_code'=>'micro_subtitle','business_table'=>'aigc_micro_video_task','business_id'=>$task['id'],'status'=>'running','progress'=>10,'request_summary'=>['task_id'=>$task['id']],'result_summary'=>[],'estimated_tenant_cost'=>$quote['tenant'],'estimated_user_price'=>$quote['user'],'idempotency_key'=>sha1('micro-subtitle|'.$attempt),'create_time'=>$now,'update_time'=>$now]);
            $log=AiConsumptionLog::create(['consume_no'=>$no,'app_task_id'=>$app['id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>MicroVideoStore::APP,'action_code'=>'micro_subtitle','resource_type'=>'app_api','product_id'=>$quote['snapshot']['product_id'],'sku_id'=>$quote['snapshot']['sku_id'],'api_code'=>'stt','protocol'=>'application_api','provider'=>'power_market','quantity'=>1,'usage_unit'=>'per_call','price_snapshot'=>$quote,'request_summary'=>['task_id'=>$task['id'],'ignore_timestamps'=>0],'response_summary'=>[],'usage_snapshot'=>['calls'=>1],'run_status'=>'running','billing_status'=>'reserved','reserved_tenant_cost'=>$quote['tenant'],'reserved_user_price'=>$quote['user'],'tenant_point_sn'=>$no.'-reserve','user_point_sn'=>$no.'-reserve','create_time'=>$now,'update_time'=>$now]);
            PointService::reserveBusinessAmountsInCurrentTransaction($tenant,$user,$quote['tenant'],$quote['user'],$no.'-reserve','视频字幕识别预占',self::extra($log->toArray(),'reserved'));
            MicroVideoStore::table('attempt')->where('id',$attempt)->update(['app_task_id'=>$app['id'],'consumption_id'=>$log['id'],'status'=>'submitting','billing_status'=>'reserved','update_time'=>$now]);
            return (int)$log['id'];
        });
    }

    private static function extra(array $log,string $stage): array
    {
        return ['app_code'=>MicroVideoStore::APP,'app_task_id'=>(int)$log['app_task_id'],'consumption_id'=>(int)$log['id'],'consume_no'=>$log['consume_no'],'billing_stage'=>$stage];
    }

    private static function uncertain(int $cid): void
    {
        $log=AiConsumptionLog::where('id',$cid)->find();
        if(!$log||in_array($log['billing_status'],['settled','refunded'],true))return;
        $log->save(['run_status'=>'needs_review','error_code'=>'stt_receipt_uncertain','error_message'=>'字幕识别回执待核对，请勿重复提交','update_time'=>time()]);
        AiAppTask::where('id',$log['app_task_id'])->update(['status'=>'needs_review','update_time'=>time()]);
    }

    private static function settle(int $id,bool $success,array $receipt): void
    {
        Db::transaction(function()use($id,$success,$receipt){
            $log=AiConsumptionLog::where('id',$id)->lock(true)->find();
            if(!$log)throw new RuntimeException('字幕消费记录不存在');
            if(in_array($log['billing_status'],['settled','refunded'],true))return;
            $tenant=(float)$log['reserved_tenant_cost'];$user=(float)$log['reserved_user_price'];
            $extra=self::extra($log->toArray(),$success?'settled':'refunded');
            if($success)PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$log['tenant_id'],(int)$log['user_id'],$tenant,$user,$tenant,$user,$log['consume_no'],'视频字幕识别结算',$extra);
            else PointService::releaseReservedBusinessAmountsInCurrentTransaction((int)$log['tenant_id'],(int)$log['user_id'],$tenant,$user,$log['consume_no'].'-release','视频字幕识别失败退回',$extra);
            $log->save(['response_summary'=>$receipt,'run_status'=>$success?'success':'failed','billing_status'=>$success?'settled':'refunded','actual_tenant_cost'=>$success?$tenant:0,'actual_user_price'=>$success?$user:0,'error_code'=>'','error_message'=>'','finish_time'=>time(),'update_time'=>time()]);
            AiAppTask::where('id',$log['app_task_id'])->update(['status'=>$success?'success':'failed','progress'=>100,'actual_tenant_cost'=>$success?$tenant:0,'actual_user_price'=>$success?$user:0,'finish_time'=>time(),'update_time'=>time()]);
            MicroVideoStore::table('attempt')->where('consumption_id',$id)->update(['billing_status'=>$success?'settled':'refunded','user_charge_points'=>$success?$user:0,'update_time'=>time()]);
        });
    }

    public static function classify(array $receipt): ?array
    {
        $http=(int)($receipt['http_status']??0);$body=$receipt['body']??null;
        if(empty($receipt['complete'])||$http<200||$http>=500||!is_array($body))return null;
        if(isset($body['code'])&&(string)$body['code']==='0')return ['success'=>false];
        $result=$body['data']['result']??null;
        if($http!==200||(string)($body['code']??'')!=='1'||!is_array($result)||!is_string($result['text']??null))return null;
        return ['success'=>true,'result'=>$result];
    }

    /** Group provider character/word timestamps without inventing timing from the script. */
    public static function cues(array $result,float $duration): array
    {
        if(trim((string)($result['text']??''))==='')return [];
        $cues=[];$current=null;$previous=0.;
        foreach($result['segments']??[] as $segment){
            $text=trim((string)($segment['text']??''));if($text==='')continue;
            $start=$segment['start']??null;$end=$segment['end']??null;
            if(!is_numeric($start)||!is_numeric($end)||!is_finite((float)$start)||!is_finite((float)$end)||$start<0||$end<=$start||$start<$previous-0.05||$end>$duration+0.5)throw new RuntimeException('字幕时间戳无效，原片和识别结果已保留');
            $start=max((float)$start,$previous);$end=min((float)$end,$duration);
            if($end<=$start)throw new RuntimeException('字幕时间戳超出成片时长');
            if($current && ($start-$current['end']>0.6||$end-$current['start']>4||mb_strlen($current['text'].$text)>20)){$cues[]=$current;$current=null;}
            if(!$current)$current=['start'=>$start,'end'=>$end,'text'=>$text];
            else{$current['text'].=$text;$current['end']=$end;}
            if(preg_match('/[。！？!?；;]$/u',$text)){$cues[]=$current;$current=null;}
            $previous=$end;
        }
        if($current)$cues[]=$current;
        if(!$cues)throw new RuntimeException('识别结果缺少字幕时间戳，原片已保留');
        return $cues;
    }

    public static function ass(array $cues,int $width,int $height,string $font): string
    {
        $size=max(18,(int)round(min($width,$height)*0.045));$margin=(int)round($height*0.07);
        $text="[Script Info]\nScriptType: v4.00+\nPlayResX: $width\nPlayResY: $height\nWrapStyle: 0\n\n[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\nStyle: Default,$font,$size,&H00FFFFFF,&H00FFFFFF,&H00000000,&H80000000,0,0,0,0,100,100,0,0,1,2,0,2,30,30,$margin,1\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        $clock=static function(float $seconds):string{$n=(int)round($seconds*100);return sprintf('%d:%02d:%02d.%02d',intdiv($n,360000),intdiv($n,6000)%60,intdiv($n,100)%60,$n%100);};
        foreach($cues as $cue){
            // Provider text cannot inject ASS override tags, escapes, or new events.
            $line=str_replace(['\\','{','}',"\r","\n"],['＼','｛','｝',' ',' '],$cue['text']);
            $text.='Dialogue: 0,'.$clock($cue['start']).','.$clock($cue['end']).',Default,,0,0,0,,'.$line."\n";
        }
        return $text;
    }

    private static function journal(int $id): string
    {
        $dir=runtime_path().'micro-video-stt-receipts';
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('字幕回执目录不可用');
        if(!is_writable($dir))throw new RuntimeException('字幕回执目录不可写');
        return $dir.'/'.$id.'.json';
    }

    private static function writeJournal(string $path,array $receipt): void
    {
        $temp=$path.'.tmp';$json=MicroVideoStore::encode($receipt);$handle=fopen($temp,'wb');
        if(!$handle)throw new RuntimeException('字幕回执保存失败');
        try{chmod($temp,0600);$offset=0;while($offset<strlen($json)){$n=fwrite($handle,substr($json,$offset));if(!$n)throw new RuntimeException('字幕回执保存失败');$offset+=$n;}fflush($handle);if(function_exists('fsync'))fsync($handle);}finally{fclose($handle);}
        if(!rename($temp,$path))throw new RuntimeException('字幕回执保存失败');
    }

    private static function request(string $url,string $key,string $audio,array $source): array
    {
        $ch=curl_init($url);$body='';
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['audio'=>new \CURLFile($audio,'audio/wav','speech.wav'),'ignore_timestamps'=>'0'],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>180,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Accept: application/json'],CURLOPT_SSL_VERIFYPEER=>UpdateSourceClient::sslVerify($source),CURLOPT_SSL_VERIFYHOST=>UpdateSourceClient::sslVerify($source)?2:0,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$body){if(strlen($body)+strlen($part)>2097152)return 0;$body.=$part;return strlen($part);}]);
        try{return ['complete'=>curl_exec($ch)!==false,'http_status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'body'=>json_decode($body,true),'transport_errno'=>curl_errno($ch)];}finally{curl_close($ch);}
    }

    public static function dto(array $task): array
    {
        if(!self::enabled($task))return ['enabled'=>false,'status'=>'off'];
        $attempt=self::attempt((int)$task['id']);$data=MicroVideoStore::json($attempt['result_json']??'');
        $original=$data['original']??[];
        $stage=$data['stage']??'pending';
        $message=match($stage){'no_audio'=>'成片没有音轨，已保留原片，未收取字幕识别费','no_speech'=>'未识别到台词，已保留原片，字幕识别费已结算','success'=>'已添加台词字幕','needs_review'=>'字幕识别回执待核对，原片已保留，请勿重复提交','invalid_timestamps'=>'字幕时间戳异常，原片和识别结果已保留，需核对识别结果','failed'=>'字幕识别失败，识别费用已退回，原片已保留',default=>$task['status']==='waiting_continue'?'字幕处理尚未完成，原片已保留，可继续处理':''};
        return ['enabled'=>true,'status'=>$stage,'message'=>$message,'user_charge_points'=>(float)($attempt['user_charge_points']??0),'original_result_url'=>empty($original['uri'])?'':\app\common\service\FileService::getFileUrlByStorage($original['uri'],$original['storage_scope'],$original['storage_engine'],$original['storage_domain'])];
    }
}
