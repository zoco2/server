<?php
require dirname(__DIR__).'/app/common/service/app/aigc_micro_video/MicroVideoSubtitles.php';
require dirname(__DIR__).'/app/common/service/app/aigc_micro_video/MicroVideoMedia.php';
use app\common\service\app\aigc_micro_video\MicroVideoSubtitles as S;
use app\common\service\app\aigc_micro_video\MicroVideoMedia as M;
$checks=0;
function check($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function rejects(callable $f,string $message){try{$f();}catch(RuntimeException $e){check(true,$message);return;}throw new RuntimeException($message);}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/micro-video-stt.json'),true,512,JSON_THROW_ON_ERROR);
$cues=S::cues($fixture,9.482);
check(count($cues)>=2,'Split the real character timestamps at the spoken pause');
check($cues[0]['start']===0.08 && $cues[0]['end']===2.0,'Use actual speech timestamps');
check($cues[1]['start']===4.32,'Do not display subtitles across the 2.32 second silence');
check(implode('',array_column($cues,'text'))===implode('',array_column($fixture['segments'],'text')),'No recognized characters lost');
check(S::cues(['text'=>'','segments'=>[]],30)===[],'An empty successful transcription needs no captions');
rejects(fn()=>S::cues(['text'=>'台词','segments'=>[]],10),'Missing timestamps cannot be fabricated');
foreach([['start'=>-1,'end'=>1],['start'=>0,'end'=>0],['start'=>0,'end'=>20],['start'=>NAN,'end'=>1]] as $bad){
    rejects(fn()=>S::cues(['text'=>'台词','segments'=>[$bad+['text'=>'台词']]],10),'Reject invalid timing');
}
rejects(fn()=>S::cues(['text'=>'重叠','segments'=>[['start'=>0,'end'=>2,'text'=>'重'],['start'=>1,'end'=>3,'text'=>'叠']]],10),'Reject overlapping cues');
$ok=['complete'=>true,'http_status'=>200,'body'=>['code'=>1,'data'=>['result'=>$fixture]]];
check(S::classify($ok)['success'],'Accept the actual STT response envelope');
check(S::classify(array_replace($ok,['complete'=>false]))===null,'Truncated success remains uncertain');
check(S::classify(array_replace($ok,['http_status'=>502]))===null,'Gateway failure cannot release a possibly charged request');
check(S::classify(['complete'=>true,'http_status'=>200,'body'=>['code'=>0]])===['success'=>false],'Explicit supplier rejection can refund');
check(S::classify(['complete'=>true,'http_status'=>200,'body'=>['code'=>1,'data'=>['result'=>['text'=>'','segments'=>[]]]]])['success'],'No speech is still a billable successful recognition');
$ass=S::ass([['start'=>0.08,'end'=>2.0,'text'=>"{\\pos(0,0)}测试\nDialogue: fake"]],720,1280,'Noto Sans CJK SC');
check(!str_contains($ass,'{\\pos') && substr_count($ass,"\nDialogue: ")===1,'Escape ASS commands and line injection');
check(str_contains($ass,'0:00:00.08,0:00:02.00'),'Preserve centisecond timing');

// Optional real media smoke test. Use a local video containing the two spoken fixture sentences.
if($input=getenv('MICRO_VIDEO_SUBTITLE_TEST_VIDEO')){
    M::subtitlePreflight();
    $dir=getenv('MICRO_VIDEO_SUBTITLE_TEST_OUTPUT')?:sys_get_temp_dir().'/subtitle-test-'.bin2hex(random_bytes(6));
    if(!is_dir($dir))mkdir($dir,0700,true);
    $call=static function($method,...$args){$r=new ReflectionMethod(M::class,$method);return $r->invoke(null,...$args);};
    $probe=$call('probe',$input);
    $call('extractSubtitleAudio',$input,$dir.'/speech.wav');
    $header=file_get_contents($dir.'/speech.wav',false,null,0,44);
    check(substr($header,0,4)==='RIFF' && substr($header,8,4)==='WAVE','FFmpeg extracts an actual WAV');
    $output=$call('burnSubtitles',$input,$dir,$cues,$probe);
    $final=$call('probe',$output);
    check(abs($final['duration']-$probe['duration'])<0.1 && $final['has_audio'],'Burning captions preserves duration and audio');
    echo "Rendered: $output\n";
}
echo "PASS $checks subtitle checks\n";
