<?php
namespace app\common\service\app\aigc_micro_video;

use app\common\service\app\aigc_video\AigcVideoService as Video;
use think\facade\Db;
use InvalidArgumentException;
use RuntimeException;

/** A role is ready only after its selected voice has been extracted and persisted. */
final class MicroVideoLibraryVoice
{
    public const ACTION = 'library_role_voice';
    public static function pending(array $body): bool
    { return in_array($body['voice_generation']['status'] ?? '', ['waiting_image','queued','running','extracting','unknown'], true); }

    public static function prepare(int $tenant, array $voice, bool $resumeAccepted = false, int $user = 0): array
    {
        if (($voice['request']['model_id'] ?? '') === MicroVideoSeedVoice::MODEL) return MicroVideoSeedVoice::prepare($tenant, $user, $voice);
        $p = array_intersect_key((array)($voice['request'] ?? []), array_flip(['prompt','channel','model_id','market_product_id','market_sku_id','resource_type','quality','ratio','duration','quantity','mode','expected_points']));
        // Request's global trim filter turns JSON integers into strings; normalize before queueing.
        // New auditions use 480P and exactly four seconds. Previously accepted jobs retain their frozen terms.
        $duration = $p['duration'] ?? null;
        $duration = is_int($duration) || is_string($duration)
            ? filter_var($duration, FILTER_VALIDATE_INT, ['options'=>['min_range'=>4,'max_range'=>15]])
            : false;
        $quality = strtolower((string)($p['quality'] ?? ''));
        $current = $quality === '480p' && $duration === 4;
        $legacy = $resumeAccepted && (($quality === '480p' && $duration !== false) || ($quality === '768p' && $duration === 4));
        if (!preg_match('/^market_video_app:full_video:\d+$/', (string)($p['model_id'] ?? '')) || (!$current && !$legacy))
            throw new InvalidArgumentException('声音生成设置已失效，请刷新页面后重试。');
        $p['duration'] = $duration;
        $text = trim((string)($voice['text'] ?? '')); $direction = trim((string)($voice['direction'] ?? ''));
        $textLimit = $legacy ? 40 : 20;
        if ($text === '' || mb_strlen($text) > $textLimit || mb_strlen($direction) > 1000 || trim((string)($p['prompt'] ?? '')) === '') throw new InvalidArgumentException('请填写'.$textLimit.'字以内的试音台词和有效声音要求');
        $p['reference_images'] = []; $p['reference_assets'] = []; $p['quantity'] = 1;
        $quote = Video::estimate($tenant, $p);
        if (!isset($p['expected_points']) || !is_numeric($p['expected_points']) || abs((float)$p['expected_points']-(float)$quote['user_charge_points'])>0.00001) throw new InvalidArgumentException('声音生成费用已变化，请重新确认');
        return ['request'=>$p,'text'=>$text,'direction'=>$direction];
    }

    public static function create(int $tenant, int $user, array $p): array
    {
        MicroVideoService::available($tenant);
        if($user<=0)throw new InvalidArgumentException('请先登录');
        $key=MicroVideoWorkbenchPolicy::key((string)($p['batch_key']??''));
        $hash=MicroVideoWorkbenchPolicy::hash($p);
        $prior=MicroVideoWorkbenchJobs::table()->where(['tenant_id'=>$tenant,'user_id'=>$user,'idempotency_key'=>$key])->find();
        if($prior){if($prior['action']!==self::ACTION||$prior['input_hash']!==$hash)throw new InvalidArgumentException('请求标识已用于其他内容');return self::response($prior);}
        $voice=self::prepare($tenant,(array)($p['voice']??[]),false,$user);
        $images=MicroVideoMedia::validateImages($tenant,$user,[(array)($p['image']??[])],1);
        return Db::transaction(function()use($tenant,$user,$p,$key,$hash,$voice,$images){
            Db::name('user')->where('id',$user)->lock(true)->find();
            $prior=MicroVideoWorkbenchJobs::table()->where(['tenant_id'=>$tenant,'user_id'=>$user,'idempotency_key'=>$key])->find();
            if($prior){if($prior['input_hash']!==$hash)throw new InvalidArgumentException('请求标识已用于其他内容');return self::response($prior);}
            $record=(array)($p['record']??[]);$body=[];
            if(!empty($record['id'])){
                $old=MicroVideoWorkbenchRecords::owned($tenant,$user,'role',(int)$record['id'],false,true,true);
                $body=MicroVideoLibraryGeneration::decorate($old,MicroVideoStore::json($old['body_json']));
                if(self::pending($body)||in_array($body['ai_generation']['status']??'',['queued','running','unknown'],true))throw new InvalidArgumentException('角色仍在生成中，请等待当前任务');
            }
            // A replacement upload becomes the voice source; an unchanged multi-view cover keeps its original.
            if (($images[0]['uri'] ?? '') !== ($body['images'][0]['uri'] ?? '') || empty($body['image_source']['images']))
                $body['image_source']=['images'=>$images];
            $body['description']=(string)($p['description']??'');$body['images']=$images;$body['creation_method']=$body['creation_method']??'photo';
            $saved=MicroVideoWorkbenchRecords::save($tenant,$user,['kind'=>'role','id'=>(int)($record['id']??0),'revision'=>(int)($record['revision']??0),'title'=>(string)($p['title']??''),'body'=>$body]);
            return self::enqueue($tenant,$user,$saved,$voice,$key,$hash);
        });
    }

    public static function enqueue(int $tenant,int $user,array $role,array $voice,string $key,string $hash='',int $taskId=0): array
    {
        if (($voice['request']['model_id'] ?? '') === MicroVideoSeedVoice::MODEL) MicroVideoSeedVoice::requireTrial($tenant,$user);
        $prior=MicroVideoWorkbenchJobs::table()->where(['tenant_id'=>$tenant,'user_id'=>$user,'idempotency_key'=>$key])->find();
        if($prior)return self::response($prior);
        // Feed the uploaded portrait to the omni model, never replace the gallery's display image.
        // Text-only roles have no upload and fall back to their generated image.
        $image=$role['body']['image_source']['images'][0]??$role['body']['images'][0]??null;
        if(!$image)throw new InvalidArgumentException('角色缺少形象参考');
        $image=MicroVideoMedia::validateImages($tenant,$user,[$image],1)[0];
        $request=$voice+['image'=>$image,'name'=>$role['title']];
        if($taskId)$request['task_id']=$taskId;
        $id=MicroVideoWorkbenchJobs::table()->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$role['id'],'project_revision'=>$role['revision'],
            'action'=>self::ACTION,'idempotency_key'=>$key,'input_hash'=>$hash?:MicroVideoWorkbenchPolicy::hash($request),'status'=>'queued',
            'request_json'=>MicroVideoStore::encode($request),'receipt_json'=>'{}','result_json'=>'{}','next_run_at'=>time(),'create_time'=>time(),'update_time'=>time()]);
        $body=$role['body'];$body['voice_generation']=['job_id'=>$id,'status'=>'queued','text'=>$voice['text']??'','direction'=>$voice['direction']??''];
        MicroVideoWorkbenchRecords::save($tenant,$user,['kind'=>'role','id'=>$role['id'],'revision'=>$role['revision'],'title'=>$role['title'],'body'=>$body]);
        return ['job_id'=>$id,'record_id'=>$role['id'],'kind'=>'role','status'=>'queued'];
    }

    /** Attach an already accepted voice task; never creates or charges a replacement. */
    public static function adopt(int $tenant,int $user,int $roleId,int $taskId): array
    {
        return Db::transaction(function()use($tenant,$user,$roleId,$taskId){
            $record=MicroVideoWorkbenchRecords::owned($tenant,$user,'role',$roleId,false,true,true);
            $role=MicroVideoWorkbenchRecords::dto($record);
            $task=Db::name('aigc_video_task')->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$taskId,'delete_time'=>0])->find();
            if(!$task)throw new InvalidArgumentException('声音任务不存在或无权访问');
            $images=is_array($task['reference_images'])?$task['reference_images']:MicroVideoStore::json($task['reference_images']);
            $targets=array_filter(array_map(static fn($image)=>ltrim((string)(parse_url($image['uri']??'',PHP_URL_PATH)?:''),'/'),
                array_merge($role['body']['images']??[], $role['body']['image_source']['images']??[])));
            $matches=array_filter($images,static fn($value)=>in_array(ltrim((string)(parse_url(is_string($value)?$value:($value['uri']??''),PHP_URL_PATH)?:''),'/'),$targets,true));
            if(!$matches)throw new InvalidArgumentException('声音任务与该角色参考图不一致');
            $prior=$role['body']['voice_generation']??[];
            if(!empty($prior['job_id'])){
                $job=MicroVideoWorkbenchJobs::owned($tenant,$user,(int)$prior['job_id']);
                $request=MicroVideoStore::json($job['request_json']);$receipt=MicroVideoStore::json($job['receipt_json']);
                if((int)($request['task_id']??$receipt['task_id']??0)===$taskId)return self::response($job);
                if(self::pending($role['body']))throw new InvalidArgumentException('角色已有声音任务');
            }
            return self::enqueue($tenant,$user,$role,['text'=>'你好，很高兴认识你，让我们开始吧。','direction'=>''],'role-voice-existing-'.$roleId.'-'.$taskId,'',$taskId);
        });
    }

    public static function decorate(array $record,array $body): array
    {
        $id=(int)($body['voice_generation']['job_id']??0);if(!$id)return $body;
        $job=MicroVideoWorkbenchJobs::table()->where(['id'=>$id,'tenant_id'=>$record['tenant_id'],'user_id'=>$record['user_id'],'project_id'=>$record['id'],'action'=>self::ACTION])->find();
        if(!$job)return $body;
        $body['voice_generation']['status']=$job['status'];
        $body['voice_generation']['error']=$job['status']==='failed'?($job['error']==='NO_AUDIO'?'生成结果没有有效音轨，请重新生成声音。':'声音生成或保存未完成，请重新生成声音。'):'';
        return $body;
    }

    private static function response(array $job): array
    { return ['job_id'=>(int)$job['id'],'record_id'=>(int)$job['project_id'],'kind'=>'role','status'=>$job['status']]; }
    private static function write(int $id,array $data): void
    { MicroVideoWorkbenchJobs::table()->where('id',$id)->update($data+['update_time'=>time()]); }

    public static function run(array $job,bool $reconcileOnly=false): void
    {
        $id=(int)$job['id'];$tenant=(int)$job['tenant_id'];$user=(int)$job['user_id'];$request=MicroVideoStore::json($job['request_json']);$receipt=MicroVideoStore::json($job['receipt_json']);
        if (($request['request']['model_id'] ?? '') === MicroVideoSeedVoice::MODEL) { MicroVideoSeedVoice::run($job,$reconcileOnly); return; }
        $taskId=(int)($receipt['task_id']??$request['task_id']??0);$submitted=false;
        try{
            $record=MicroVideoWorkbenchRecords::owned($tenant,$user,'role',(int)$job['project_id']);
            $body=MicroVideoStore::json($record['body_json']);
            if((int)($body['voice_generation']['job_id']??0)!==$id){self::write($id,['status'=>'failed','error'=>'角色已切换声音任务']);return;}
            if(!$taskId){
                if($job['status']!=='queued'||$reconcileOnly){self::write($id,['status'=>'unknown','error'=>'声音提交状态待确认，不会重复生成','next_run_at'=>time()+60]);return;}
                $voice=self::prepare($tenant,$request,true);$p=$voice['request'];
                $image=MicroVideoMedia::validateImages($tenant,$user,[$request['image']],1)[0];
                $p['reference_images']=[$image['uri']];$p['reference_assets']=[['type'=>'image','uri'=>$image['uri'],'url'=>$image['url']??$image['uri']]];
                $p['idempotency_key']='library-role-voice-'.$id;
                self::write($id,['status'=>'running']);$submitted=true;
                $accepted=Video::generate($tenant,$user,$p);$taskId=(int)($accepted['task_id']??$accepted['id']??0);
                if(!$taskId)throw new RuntimeException('声音提交状态待确认');
                $receipt=['task_id'=>$taskId];self::write($id,['receipt_json'=>MicroVideoStore::encode($receipt)]);
            }
            $task=Video::taskDetail($tenant,$taskId,$user);
            if(in_array($task['status'],['failed','canceled','cancelled'],true)){self::write($id,['status'=>'failed','error'=>'声音模型未完成生成']);return;}
            if($task['status']!=='success'){self::write($id,['status'=>'running','next_run_at'=>time()+10]);return;}
            $result=$task['results'][0]??[];$uri=$result['video_url']??$result['video_uri']??'';
            if($uri==='')throw new RuntimeException('声音媒体尚未保存');
            $voice=$receipt['voice']??null;
            if(!$voice){
                $voice=MicroVideoMedia::extractRoleVoice($tenant,$user,$uri)+['name'=>$request['name'].' · AI声音.wav','source'=>'generated','description'=>$request['direction']??'','text'=>$request['text']??'','model'=>'全能视频生成','task_id'=>$taskId,'source_video_url'=>$uri];
                $receipt['voice']=$voice;self::write($id,['receipt_json'=>MicroVideoStore::encode($receipt)]);
            }
            Db::transaction(function()use($tenant,$user,$job,$id,$voice){
                $fresh=MicroVideoWorkbenchRecords::owned($tenant,$user,'role',(int)$job['project_id'],false,true,true);$body=MicroVideoStore::json($fresh['body_json']);
                if((int)($body['voice_generation']['job_id']??0)!==$id)throw new RuntimeException('角色声音任务已变更');
                $body['voice']=$voice;$body['voice_generation']['status']='success';
                MicroVideoWorkbenchRecords::save($tenant,$user,['kind'=>'role','id'=>$fresh['id'],'revision'=>$fresh['revision'],'title'=>$fresh['title'],'body'=>$body]);
                self::write($id,['status'=>'success','result_json'=>MicroVideoStore::encode(['voice'=>$voice]),'error'=>'']);
            });
        }catch(\Throwable $e){
            self::write($id,['status'=>$submitted&&!$taskId?'unknown':'failed','error'=>$e->getMessage()==='NO_AUDIO'?'NO_AUDIO':mb_substr($e->getMessage(),0,300),'next_run_at'=>time()+30]);
        }
    }
}
