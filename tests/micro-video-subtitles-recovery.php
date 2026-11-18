<?php
// Exercise the real subtitle orchestration against in-memory persistence and a fake STT transport.
namespace SubtitleTest {
    final class Memory {
        public static array $tables=[],$ledger=[];
        public static int $calls=0,$merges=0,$renders=0;
        public static bool $audio=true,$failRender=false,$failSettle=false;
        public static array $response=[];
        public static bool $complete=true;
        public static string $dir;
    }
    class Row extends \ArrayObject {
        public function __construct(private string $table,array $row){parent::__construct($row);}
        public function toArray(){return $this->getArrayCopy();}
        public function save(array $data){Memory::$tables[$this->table][$this['id']]=array_replace($this->toArray(),$data);$this->exchangeArray(Memory::$tables[$this->table][$this['id']]);}
    }
    class Query {
        private array $filters=[];private bool $descending=false;
        public function __construct(private string $table,private bool $model=false){}
        public function where($key,$value=null){foreach(is_array($key)?$key:[$key=>$value] as $k=>$v)$this->filters[$k]=$v;return $this;}
        public function order(...$args){$this->descending=true;return $this;}
        public function lock($flag){return $this;}
        private function rows(){return array_filter(Memory::$tables[$this->table]??[],function($r){foreach($this->filters as $k=>$v)if(($r[$k]??null)!=$v)return false;return true;});}
        public function find(){ $rows=$this->rows();if($this->descending)krsort($rows);$r=reset($rows);return $r?($this->model?new Row($this->table,$r):$r):null;}
        public function update(array $values){foreach($this->rows() as $id=>$r)Memory::$tables[$this->table][$id]=array_replace($r,$values);}
        public function insertGetId(array $r){$id=count(Memory::$tables[$this->table]??[])+1;Memory::$tables[$this->table][$id]=$r+['id'=>$id,'consumption_id'=>0,'billing_status'=>'none','user_charge_points'=>0];return $id;}
    }
}
namespace think\facade {
    class Db {public static function transaction($call){$tables=\SubtitleTest\Memory::$tables;$ledger=\SubtitleTest\Memory::$ledger;try{return $call();}catch(\Throwable $e){\SubtitleTest\Memory::$tables=$tables;\SubtitleTest\Memory::$ledger=$ledger;throw $e;}}}
}
namespace app\common\model\ai {
    class AiAppTask {
        public static function where(...$args){return (new \SubtitleTest\Query(static::class,true))->where(...$args);}
        public static function create($data){$q=new \SubtitleTest\Query(static::class,true);$id=$q->insertGetId($data);return $q->where('id',$id)->find();}
    }
    class AiConsumptionLog extends AiAppTask {}
}
namespace app\common\service\point {
    class PointService {
        public static function assertCanConsumeAmounts(...$args){}
        public static function reserveBusinessAmountsInCurrentTransaction(...$args){\SubtitleTest\Memory::$ledger[]=['reserve',$args[2],$args[3]];}
        public static function settleReservedBusinessAmountsInCurrentTransaction(...$args){if(\SubtitleTest\Memory::$failSettle){\SubtitleTest\Memory::$failSettle=false;throw new \RuntimeException('ledger unavailable');}\SubtitleTest\Memory::$ledger[]=['settle',$args[4],$args[5]];}
        public static function releaseReservedBusinessAmountsInCurrentTransaction(...$args){\SubtitleTest\Memory::$ledger[]=['release',$args[2],$args[3]];}
    }
}
namespace app\common\service\update {
    class UpdateSourceClient {public static function getSource(){return ['active_base_url'=>'https://example.invalid','active_api_key'=>'test'];}public static function sslVerify($s){return true;}}
}
namespace app\common\service\app\aigc_llm\agent {
    class AgentTranscriptionTool {public static function quote($tenant){return ['tenant'=>4.5,'user'=>7.25,'snapshot'=>['product_id'=>12,'sku_id'=>25]];}}
}
namespace app\common\service\app\aigc_micro_video {
    use SubtitleTest\Memory;
    function runtime_path(){return Memory::$dir.'/';}
    function curl_init($url){if(!str_ends_with($url,'/api/v1/apps/voice_tts/stt'))throw new \RuntimeException('wrong endpoint');return new \stdClass;}
    function curl_setopt_array($ch,$options){if($options[CURLOPT_POSTFIELDS]['ignore_timestamps']!=='0')throw new \RuntimeException('timestamps must be enabled');$ch->writer=$options[CURLOPT_WRITEFUNCTION];}
    function curl_exec($ch){Memory::$calls++;($ch->writer)($ch,json_encode(Memory::$response));return Memory::$complete;}
    function curl_getinfo(...$args){return 200;}
    function curl_errno($ch){return Memory::$complete?0:28;}
    function curl_close($ch){}
    class MicroVideoStore {
        public const APP='aigc_micro_video';
        public static function json($v):array{return is_array($v)?$v:(json_decode($v,true)?:[]);}
        public static function encode($v):string{return json_encode($v,JSON_THROW_ON_ERROR);}
        public static function table($suffix){return new \SubtitleTest\Query($suffix);}
        public static function segments($id){return [['id'=>101,'sort'=>1,'status'=>'pending','duration'=>10,'title'=>'测试','prompt'=>'测试提示词','image_indices'=>'[]','start_state'=>'开始','end_state'=>'结束','continuity'=>'连续']];}
        public static function segmentData($row){return $row;}
    }
    class MicroVideoSettings {public static function get($tenant){return ['video_model_ids'=>['model']];}}
    class MicroVideoCatalog {
        public static function model(...$args){return [];}
        public static function spec(...$args){return [];}
        public static function quote(...$args){return ['user_charge_points'=>100.,'tenant_cost_points'=>50.];}
    }
    class MicroVideoFramePolicy {public static function identity(...$args){return 'frames';}}
    class MicroVideoMedia {
        public static function merge($task,$segments){Memory::$merges++;return ['uri'=>'original.mp4','duration'=>10.0,'has_audio'=>Memory::$audio];}
        public static function subtitleMovie($task,$original,$recognize,$finished){
            $cues=$recognize(Memory::$dir.'/speech.wav');$final=$original;
            if($cues){Memory::$renders++;if(Memory::$failRender){Memory::$failRender=false;throw new \RuntimeException('render failed');}$final['uri']='subtitled.mp4';}
            $finished($final);return $final;
        }
    }
}
namespace {
    use SubtitleTest\Memory as M;
    use app\common\service\app\aigc_micro_video\MicroVideoSubtitles as S;
    use app\common\service\app\aigc_micro_video\MicroVideoStore as Store;
    use app\common\model\ai\AiConsumptionLog as Log;
    require dirname(__DIR__).'/app/common/service/app/aigc_micro_video/MicroVideoSubtitles.php';
    require dirname(__DIR__).'/app/common/service/app/aigc_micro_video/MicroVideoPolicy.php';
    require dirname(__DIR__).'/app/common/service/app/aigc_micro_video/MicroVideoService.php';
    M::$dir=sys_get_temp_dir().'/micro-subtitle-test-'.bin2hex(random_bytes(6));mkdir(M::$dir,0700);file_put_contents(M::$dir.'/speech.wav','RIFFtest');
    $checks=0;
    function check($ok,$message){global $checks;$checks++;if(!$ok)throw new \RuntimeException($message);}
    function fails($f){try{$f();}catch(\RuntimeException|\InvalidArgumentException $e){return;}throw new \RuntimeException('Expected recoverable failure');}
    function task($id){return ['id'=>$id,'tenant_id'=>1,'user_id'=>2,'params_json'=>'{"subtitles_enabled":true}','confirmed_quote'=>Store::encode(['subtitles'=>app\common\service\app\aigc_llm\agent\AgentTranscriptionTool::quote(1)])];}
    $fixture=json_decode(file_get_contents(__DIR__.'/fixtures/micro-video-stt.json'),true);
    $success=['code'=>1,'data'=>['result'=>$fixture]];M::$response=$success;
    try{
        check(S::quote(task(1),true)['user']===7.25,'Use tenant sale price instead of upstream test price');
        check(S::quote(task(1),false)===[],'No subtitle quote when disabled');
        check(S::process(task(1),[])['uri']==='subtitled.mp4','Deliver the subtitled movie');
        S::process(task(1),[]);
        check(M::$calls===1 && M::$merges===1 && M::$renders===1,'Repeated worker delivery does not repeat generation, STT or render');
        check(M::$ledger===[['reserve',4.5,7.25],['settle',4.5,7.25]],'One separate reservation and settlement');
        check(S::quote(task(1),true)===[],'No new fee after settlement');

        M::$failRender=true;fails(fn()=>S::process(task(2),[]));$before=M::$calls;$merges=M::$merges;$ledger=M::$ledger;
        check(S::process(task(2),[])['uri']==='subtitled.mp4','Resume failed caption rendering');
        check(M::$calls===$before && M::$merges===$merges && M::$ledger===$ledger,'Render retry reuses original, receipt and settlement');

        M::$audio=false;$before=M::$calls;$ledger=M::$ledger;
        check(S::process(task(3),[])['uri']==='original.mp4','No audio delivers original');
        check(M::$calls===$before && M::$ledger===$ledger,'No audio never invokes or charges STT');M::$audio=true;

        M::$response=['code'=>1,'data'=>['result'=>['text'=>'','segments'=>[]]]];$before=M::$calls;
        check(S::process(task(4),[])['uri']==='original.mp4' && M::$calls===$before+1,'Successful empty recognition keeps original');
        check(end(M::$ledger)[0]==='settle','Empty recognized speech still settles one successful call');

        M::$complete=false;M::$response=$success;fails(fn()=>S::process(task(5),[]));$before=M::$calls;$ledger=M::$ledger;
        fails(fn()=>S::process(task(5),[]));
        check(M::$calls===$before && M::$ledger===$ledger,'Ambiguous timeout is never automatically resubmitted or refunded');
        check(S::quote(task(5),true)===[],'No second quote for uncertain paid call');M::$complete=true;

        M::$response=['code'=>0];fails(fn()=>S::process(task(6),[]));check(end(M::$ledger)[0]==='release','Explicit rejection refunds');
        check(S::quote(task(6),true)['user']===7.25,'Explicit continuation of rejected call gets a new quote');
        $before=M::$calls;$merges=M::$merges;S::retry(task(6));M::$response=$success;S::process(task(6),[]);
        check(M::$calls===$before+1 && M::$merges===$merges,'Explicit rejection retry only redoes STT, keeps movie');

        M::$failSettle=true;fails(fn()=>S::process(task(7),[]));$before=M::$calls;
        S::process(task(7),[]);check(M::$calls===$before,'Settlement retry uses the persisted receipt');
        // Simulate DB receipt loss after the file journal was durably written.
        M::$failSettle=true;fails(fn()=>S::process(task(8),[]));$before=M::$calls;
        $attempt=Store::table('attempt')->where(['task_id'=>8,'kind'=>'subtitle'])->find();$data=Store::json($attempt['result_json']);unset($data['receipt']);Store::table('attempt')->where('id',$attempt['id'])->update(['result_json'=>Store::encode($data)]);
        S::process(task(8),[]);check(M::$calls===$before,'Private journal recovers a receipt lost before DB persistence');

        $priced=task(9)+['config_snapshot'=>'{"frame_feature_version":1}','plan_revision'=>2,'continuity'=>'连续'];
        $priced['params_json']='{"model_id":"model","quality":"1080P","ratio":"9:16"}';
        $service=app\common\service\app\aigc_micro_video\MicroVideoService::class;
        $with=$service::quoteTask($priced,true);$without=$service::quoteTask($priced,false);
        check($with['user_charge_points']===107.25 && $with['tenant_cost_points']===54.5,'Server combines video and STT amounts at both billing levels');
        check($without['user_charge_points']===100. && $without['subtitle_points']===0,'Unselected subtitle adds no fee');
        $priced+=['quote_json'=>Store::encode($with),'quote_token'=>'confirmed','quote_expires'=>time()+600];
        $validate=new \ReflectionMethod($service,'validateQuote');$request=['plan_revision'=>2,'quote_token'=>'confirmed'];
        $validate->invoke(null,$priced,$request,$with);check(true,'Unchanged combined quote validates');
        fails(fn()=>$validate->invoke(null,$priced,$request,$without));check(true,'A quote cannot be reused with different subtitle selection');
        $priced['quote_expires']=time()-1;fails(fn()=>$validate->invoke(null,$priced,$request,$with));check(true,'Expired subtitle quotation is rejected');
        echo "PASS $checks subtitle recovery and billing checks\n";
    }finally{
        foreach(glob(M::$dir.'/micro-video-stt-receipts/*')?:[] as $path)unlink($path);
        if(is_dir(M::$dir.'/micro-video-stt-receipts'))rmdir(M::$dir.'/micro-video-stt-receipts');unlink(M::$dir.'/speech.wav');rmdir(M::$dir);
    }
}
