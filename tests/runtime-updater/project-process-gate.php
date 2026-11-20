<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/common/service/update/ProjectUpdateProcessGate.php';
use app\common\service\update\ProjectUpdateProcessGate;
function gateCheck(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
$work=sys_get_temp_dir().'/aigc-project-gate-'.bin2hex(random_bytes(4));mkdir($work);mkdir($work.'/other');
file_put_contents($work.'/think','<?php sleep(20);');file_put_contents($work.'/other/think','<?php sleep(20);');
ProjectUpdateProcessGate::assertDrained($work);
$pipes=[];$other=proc_open([PHP_BINARY,$work.'/other/think','ai:task-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);usleep(100000);
ProjectUpdateProcessGate::assertDrained($work);proc_terminate($other);foreach($pipes as $p)fclose($p);proc_close($other);
foreach(['ai:task-worker','brand:worker','brand:maintain','canvas:subagent-worker','home-agent:worker','micro-video:worker'] as $command) foreach([true,false] as $absolute){
    $pipes=[];$worker=proc_open([PHP_BINARY,$absolute?$work.'/think':'think',$command],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$work);usleep(100000);
    $blocked=false;try{ProjectUpdateProcessGate::assertDrained($work);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'本项目任务');}
    gateCheck($blocked,'active same-project worker was not blocked');proc_terminate($worker);foreach($pipes as $p)fclose($p);proc_close($worker);
}
ProjectUpdateProcessGate::assertDrained($work);
$pidDir=$work.'/runtime/log/workerman';
mkdir($pidDir,0755,true);
$pidFile=$pidDir.'/workerman.pid';
$masterTitle='WorkerMan: master process  start_file=';
unset($_ENV['WORKERMAN_AI_TASK_GROUP']);
putenv('PHP_WORKERMAN_AI_TASK_GROUP');
foreach ([
    ['default', $masterTitle.$work.'/think', false],
    ['false', $masterTitle.$work.'/think', false],
    ['off', $masterTitle.$work.'/think', false],
    ['0', $masterTitle.$work.'/think', false],
    ['true', $masterTitle.$work.'/think', true],
    ['true', $masterTitle.$work.'/other/think', false],
    ['true', 'unrelated process using a recycled PID', false],
] as [$setting,$title,$expected]) {
    if($setting !== 'default')file_put_contents($work.'/.env',"[WORKERMAN]\nAI_TASK_GROUP = $setting\n");
    $pipes=[];
    $master=proc_open([PHP_BINARY,'-r','cli_set_process_title($argv[1]); echo "ready\\n"; sleep(20);',$title],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    try {
        gateCheck(fgets($pipes[1])==="ready\n",'fixture master did not set its process title');
        file_put_contents($pidFile,(string)proc_get_status($master)['pid']);
        $blocked=false;
        try { ProjectUpdateProcessGate::assertDrained($work); }
        catch(RuntimeException $e) {
            if(!str_contains($e->getMessage(),'Workerman'))throw $e;
            $blocked=true;
        }
        gateCheck($blocked===$expected,"unexpected Workerman decision for $setting and $title");
    } finally {
        proc_terminate($master);
        foreach($pipes as $pipe)fclose($pipe);
        proc_close($master);
    }
}
ProjectUpdateProcessGate::assertDrained($work); // Stale pidfile after the master exits.
echo "PASS: read-only process gate blocks absolute/relative same-project workers, ignores other projects and permits drained site\n";
