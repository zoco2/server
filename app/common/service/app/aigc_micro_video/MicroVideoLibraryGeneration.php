<?php
namespace app\common\service\app\aigc_micro_video;

use app\common\service\point\PointService;
use think\facade\Db;
use InvalidArgumentException;

/** A role and its durable image job are created atomically; workers save the result. */
final class MicroVideoLibraryGeneration
{
    public const ROLE_ACTION = 'library_role_image';
    public const PRODUCT_ACTION = 'library_product_image';

    public static function create(int $tenant, int $user, array $p): array
    {
        MicroVideoService::available($tenant);
        if ($user <= 0) throw new InvalidArgumentException('请先登录');
        $kind = (string)($p['kind'] ?? 'role');
        if (!in_array($kind, ['role','product'], true)) throw new InvalidArgumentException('资料类型无效');
        $action = $kind === 'role' ? self::ROLE_ACTION : self::PRODUCT_ACTION;
        $key = MicroVideoWorkbenchPolicy::key((string)($p['batch_key'] ?? ''));
        $title = trim((string)($p['title'] ?? ''));
        $description = trim((string)($p['description'] ?? ''));
        $prompt = trim((string)($p['prompt'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120 || $prompt === '' || mb_strlen($prompt) > 10000 || mb_strlen($description) > 5000)
            throw new InvalidArgumentException('请填写有效的角色名称和生成描述');
        if ((int)($p['quantity'] ?? 1) !== 1) throw new InvalidArgumentException('每个角色只生成一张展示图');
        $identity = array_intersect_key($p, array_flip(['title','description','prompt','reference_images','channel','quality','ratio','quantity','three_view','market_sku_id','expected_points','kind','record','four_view']));
        if(isset($p['voice']))$identity['voice']=$p['voice'];
        $hash = MicroVideoWorkbenchPolicy::hash($identity);
        $prior = self::prior($tenant, $user, $key, $hash);
        if ($prior) return self::response($prior);

        $model = MicroVideoFrameCatalog::model($tenant, (string)($p['channel'] ?? ''));
        if (!preg_match('/^gpt[\s_-]*image[\s_-]*2\.6$/i', trim($model['label'])))
            throw new InvalidArgumentException('角色和商品图片固定使用 GPT Image 2.6');
        $selection = MicroVideoFrameCatalog::selection($model, (string)($p['quality'] ?? ''), (string)($p['ratio'] ?? ''));
        $images = [];
        foreach ((array)($p['reference_images'] ?? []) as $uri) $images[] = ['uri'=>(string)$uri];
        $images = $images ? MicroVideoMedia::validateImages($tenant, $user, $images, min(15, (int)$model['max_reference_images'])) : [];
        $quote = self::quote($tenant, ['selection'=>$selection, 'prompt'=>$prompt, 'images'=>$images]);
        if (!isset($p['expected_points']) || !is_numeric($p['expected_points']) || abs((float)$p['expected_points'] - (float)$quote['user_charge_points']) > 0.00001)
            throw new InvalidArgumentException('生成费用已变化，请重新估价后确认');
        PointService::assertCanConsumeAmounts($tenant, $user, (float)$quote['tenant_cost_points'], (float)$quote['user_charge_points']);
        $request = ['selection'=>$quote['selection'], 'prompt'=>$prompt, 'images'=>$images, 'quote'=>$quote];
        if($kind==='role'&&!empty($p['voice']))$request['voice']=MicroVideoLibraryVoice::prepare($tenant,(array)$p['voice'],false,$user);

        return Db::transaction(function () use ($tenant, $user, $key, $hash, $title, $description, $p, $request, $kind, $action) {
            // Serialize submissions for one owner before creating either the role or its job.
            Db::name('user')->where('id', $user)->lock(true)->find();
            $prior = self::prior($tenant, $user, $key, $hash);
            if ($prior) return self::response($prior);
            $record = (array)($p['record'] ?? []);
            $reuse = $kind === 'product';
            $oldBody = [];
            if (!empty($record['id'])) {
                $old = MicroVideoWorkbenchRecords::owned($tenant, $user, $kind, (int)$record['id'], false, true, true);
                $oldBody = self::decorate($old, MicroVideoStore::json($old['body_json']));
                if (in_array($oldBody['ai_generation']['status'] ?? '', ['queued','running','unknown'], true) || (MicroVideoLibraryVoice::pending($oldBody) && ($oldBody['ai_generation']['status'] ?? '') !== 'failed'))
                    throw new InvalidArgumentException('该资料正在生成中，请等待已有任务完成');
                $reuse = $reuse || ($oldBody['ai_generation']['status'] ?? '') === 'failed';
            }
            $body = $kind === 'role'
                ? ['description'=>$description, 'group'=>'lead', 'fixed_identity'=>true, 'images'=>[], 'creation_method'=>'text', 'three_view'=>!empty($p['three_view'])]
                : (array)($record['body'] ?? []);
            unset($body['ai_generation']);
            // Preserve the uploaded portrait independently from the generated display sheet.
            // Nested images use the shared ownership validation and asset-copy rules.
            if ($kind === 'role' && !empty($request['images'])) {
                $sameCover = ($request['images'][0]['uri'] ?? '') === ($oldBody['images'][0]['uri'] ?? '');
                $body['image_source'] = $sameCover && !empty($oldBody['image_source']['images'])
                    ? $oldBody['image_source'] : ['images'=>$request['images']];
            }
            if(isset($request['voice']))$body['voice_generation']=['status'=>'waiting_image','text'=>$request['voice']['text'],'direction'=>$request['voice']['direction']];
            if ($kind === 'role' && $reuse && !empty($oldBody['voice'])) $body['voice'] = $oldBody['voice'];
            if ($kind === 'product' && count($body['images'] ?? []) >= 15) throw new InvalidArgumentException('商品图片已达上限，请移除一张后生成');
            $role = MicroVideoWorkbenchRecords::save($tenant, $user, [
                'kind'=>$kind, 'title'=>$title, 'body'=>$body,
                'id'=>$reuse ? (int)($record['id'] ?? 0) : 0,
                'revision'=>$reuse ? (int)($record['revision'] ?? 0) : 0
            ]);
            $id = MicroVideoWorkbenchJobs::table()->insertGetId([
                'tenant_id'=>$tenant, 'user_id'=>$user, 'project_id'=>$role['id'], 'project_revision'=>$role['revision'],
                'action'=>$action, 'idempotency_key'=>$key, 'input_hash'=>$hash, 'status'=>'queued',
                'request_json'=>MicroVideoStore::encode($request), 'receipt_json'=>'{}', 'result_json'=>'{}',
                'next_run_at'=>time(), 'create_time'=>time(), 'update_time'=>time()
            ]);
            $body = $role['body'];
            $body['ai_generation'] = ['job_id'=>$id, 'status'=>'queued', 'four_view'=>!empty($p['four_view'])];
            // Initial metadata belongs to the same creation transaction and immutable version.
            $encoded = MicroVideoStore::encode($body);
            MicroVideoWorkbenchRecords::table()->where('id', $role['id'])->update(['body_json'=>$encoded]);
            MicroVideoWorkbenchRecords::table('version')->where(['record_id'=>$role['id'], 'revision'=>$role['revision']])->update(['body_json'=>$encoded]);
            return self::response(MicroVideoWorkbenchJobs::owned($tenant, $user, $id));
        });
    }

    public static function quote(int $tenant, array $request): array
    {
        $selection = $request['selection'];
        $params = $selection + ['prompt'=>$request['prompt'], 'reference_images'=>MicroVideoMedia::visionInputs($request['images']), 'quantity'=>1];
        $quote = \app\common\service\power\MarketImageModelRuntimeService::quote($tenant, $params, 1);
        if (($quote['settlement_mode'] ?? 'reserved') !== 'reserved') throw new InvalidArgumentException('图片规格不支持预报价');
        // The shared runtime may resolve a reference-image SKU instead of the catalogue's text SKU.
        $selection['market_sku_id'] = (int)$quote['market_sku_id'];
        return ['selection'=>$selection, 'market_sku_id'=>(int)$quote['market_sku_id'], 'tenant_cost_points'=>(float)$quote['tenant_cost_points'], 'user_charge_points'=>(float)$quote['user_charge_points'], 'approximate'=>false];
    }

    private static function prior(int $tenant, int $user, string $key, string $hash): ?array
    {
        $row = MicroVideoWorkbenchJobs::table()->where(['tenant_id'=>$tenant, 'user_id'=>$user, 'idempotency_key'=>$key])->find();
        if ($row && (!in_array($row['action'], [self::ROLE_ACTION, self::PRODUCT_ACTION], true) || $row['input_hash'] !== $hash)) throw new InvalidArgumentException('请求标识已用于其他创作内容');
        return $row;
    }

    private static function response(array $job): array
    {
        return ['job_id'=>(int)$job['id'], 'record_id'=>(int)$job['project_id'], 'kind'=>$job['action'] === self::ROLE_ACTION ? 'role' : 'product', 'status'=>$job['status']];
    }

    public static function decorate(array $record, array $body): array
    {
        $body=MicroVideoLibraryVoice::decorate($record,$body);
        // Legacy image jobs predate the three_view marker. Infer it only from the
        // owner's exact generated asset and the built-in layout, never an uploaded photo's shape.
        if ($record['kind'] === 'role' && ($body['creation_method'] ?? '') === 'text'
            && !array_key_exists('three_view', $body) && !empty($body['images'][0]['uri'])) {
            $taskId = Db::name('aigc_image_result')->where(['tenant_id'=>$record['tenant_id'], 'image_uri'=>$body['images'][0]['uri']])->value('task_id');
            $prompt = $taskId ? (string)Db::name('aigc_image_task')->where(['id'=>$taskId, 'tenant_id'=>$record['tenant_id'], 'user_id'=>$record['user_id'], 'status'=>'success'])->value('prompt') : '';
            $body['three_view'] = str_contains($prompt, '左侧为两个镜头') && str_contains($prompt, '正面特写') && str_contains($prompt, '右侧为角色三视图');
        }
        $id = (int)($body['ai_generation']['job_id'] ?? 0);
        if (!in_array($record['kind'], ['role','product'], true) || !$id) return $body;
        $action = $record['kind'] === 'role' ? self::ROLE_ACTION : self::PRODUCT_ACTION;
        $job = MicroVideoWorkbenchJobs::table()->where(['id'=>$id, 'tenant_id'=>$record['tenant_id'],
            'user_id'=>$record['user_id'], 'project_id'=>$record['id'], 'action'=>$action])->find();
        if (!$job) { unset($body['ai_generation']); return $body; }
        $status = $job['status'];
        $error = '';
        if ($status === 'failed') $error = preg_match('/点数不足|余额不足|insufficient.*(?:balance|credit)/iu', $job['error'])
            ? '上游服务账户点数不足，请联系管理员补充额度后重试。'
            : '本次生成未完成，请检查模型、余额或参考图后重新创建。';
        $body['ai_generation'] = ['job_id'=>$id, 'status'=>$status, 'error'=>$error, 'four_view'=>(bool)($body['ai_generation']['four_view'] ?? false)];
        // Older roles did not save their source separately. Recover only from this owner's role job.
        if ($record['kind'] === 'role' && empty($body['image_source']['images'])) {
            $request = MicroVideoStore::json($job['request_json']);
            $result = MicroVideoStore::json($job['result_json']);
            $cover = $body['images'][0]['uri'] ?? '';
            if (!empty($request['images']) && ($cover === '' || $cover === ($result['images'][0]['uri'] ?? '')))
                $body['image_source'] = ['images'=>$request['images']];
        }
        if ($status === 'failed') {
            $request = MicroVideoStore::json($job['request_json']);
            $body['ai_generation']['reference_images'] = array_map(static fn($image) => array_intersect_key($image, array_flip(['uri','url','name'])), (array)($request['images'] ?? []));
            if (str_contains($job['error'], '当前 SKU 不支持所选生成方式'))
                $body['ai_generation']['error'] = '参考图生成规格不匹配，请重新生成。';
        }
        return $body;
    }

    public static function complete(array $job, array $result): void
    {
        if (!in_array($job['action'], [self::ROLE_ACTION, self::PRODUCT_ACTION], true)) return;
        Db::transaction(function () use ($job, $result) {
            $kind = $job['action'] === self::ROLE_ACTION ? 'role' : 'product';
            $record = MicroVideoWorkbenchRecords::query((int)$job['tenant_id'], (int)$job['user_id'], $kind, false, true)
                ->where('id', $job['project_id'])->lock(true)->find();
            // Deleted roles are not resurrected, and an unrelated later job is never overwritten.
            if (!$record) return;
            $body = MicroVideoStore::json($record['body_json']);
            if ((int)($body['ai_generation']['job_id'] ?? 0) !== (int)$job['id'] || ($body['ai_generation']['status'] ?? '') === 'success') return;
            if (empty($result['images'])) throw new InvalidArgumentException('生成结果缺少角色图片');
            if ($kind === 'product') {
                $image = $result['images'][0];
                $images = array_values(array_filter((array)($body['images'] ?? []), static fn($v)=>($v['uri'] ?? '') !== $image['uri']));
                if (count($images) >= 15) throw new InvalidArgumentException('商品图片数量已达上限，生成结果已保留');
                $body['images'] = array_merge([$image], $images);
                $body['ai_generated_images'] = array_values(array_unique(array_merge((array)($body['ai_generated_images'] ?? []), [$image['uri']])));
                if (!empty($body['ai_generation']['four_view'])) $body['ai_four_view_images'] = array_values(array_unique(array_merge((array)($body['ai_four_view_images'] ?? []), [$image['uri']])));
            } else $body['images'] = $result['images'];
            $body['ai_generation'] = ['job_id'=>(int)$job['id'], 'status'=>'success'];
            $saved=MicroVideoWorkbenchRecords::save((int)$job['tenant_id'], (int)$job['user_id'], [
                'kind'=>$kind, 'id'=>$record['id'], 'revision'=>$record['revision'], 'title'=>$record['title'], 'body'=>$body
            ]);
            $request=MicroVideoStore::json($job['request_json']);
            if($kind==='role'&&!empty($request['voice']))MicroVideoLibraryVoice::enqueue((int)$job['tenant_id'],(int)$job['user_id'],$saved,$request['voice'],'role-image-voice-'.$job['id']);
        });
    }
}
