<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\publish_task\PublishTaskMaterialService;
use app\common\service\publish_task\PublishTaskMaterialShortage;
use app\common\service\publish_task\PublishTaskService;
use app\common\service\share\ShareServiceException;
use InvalidArgumentException;
use think\facade\Log;
use Throwable;

/** 提供当前登录用户的任务发布和查询接口，由框架默认路由分发。 */
final class PublishTaskController extends BaseApiController
{
    public array $notNeedLogin = ['detail', 'douyinShare', 'assignMaterial'];

    /** GET /api/publish_task/detail：打开员工收到的任务。 */
    public function detail()
    {
        if ($this->request->method(true) !== 'GET') {
            return $this->fail('请使用 GET 请求');
        }
        $token = $this->request->get('token');
        $detail = is_string($token)
            ? PublishTaskService::publicDetail($token) : null;
        $response = $detail === null ? $this->fail('任务链接无效或已不可用') : $this->data($detail);
        return $response->header(['Cache-Control' => 'private, no-store']);
    }

    /** 员工首次点击某一发布平台时领取素材。 */
    public function assignMaterial()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        $token = $this->request->post('token');
        $platform = $this->request->post('platform');
        try {
            $materials = PublishTaskService::assignMaterial(
                is_string($token) ? $token : '',
                is_string($platform) ? $platform : ''
            );
            $response = $this->data(['materials' => $materials]);
        } catch (PublishTaskMaterialShortage $e) {
            $response = $this->data(['missing_groups' => $e->missingGroups]);
        } catch (InvalidArgumentException $e) {
            $response = $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::channel('mns')->error(sprintf('[publish_task] assignMaterial tenant=%d exception=%s at %s:%d',
                (int)$this->request->tenantId, get_class($e), basename($e->getFile()), $e->getLine()));
            $response = $this->fail('暂时无法领取发布素材，请重试');
        }

        return $response->header(['Cache-Control' => 'private, no-store']);
    }

    /** 批量查询当前用户的成片使用次数与上限。 */
    public function materialUsage()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            $tenantId = (int)($this->userInfo['tenant_id'] ?? 0);
            if ($tenantId <= 0 || $tenantId !== (int)$this->request->tenantId) {
                throw new InvalidArgumentException('账号与租户不匹配，请重新登录');
            }
            return $this->data(PublishTaskMaterialService::usage(
                $tenantId,
                $this->userId,
                $this->request->post('type', null, null),
                $this->request->post('refs', null, null)
            ));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 为员工通知中指定的素材签名。 */
    public function douyinShare()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        $token = $this->request->post('token');
        try {
            $response = $this->data(PublishTaskService::douyinShare(is_string($token) ? $token : ''));
        } catch (InvalidArgumentException $e) {
            $response = $this->fail($e->getMessage());
        } catch (ShareServiceException $e) {
            Log::channel('mns')->error(sprintf('[publish_task] douyinShare tenant=%d: %s',
                (int)$this->request->tenantId, $e->getMessage()));
            $response = $this->fail($e->getMessage());
        } catch (Throwable $e) {
            $reference = bin2hex(random_bytes(6));
            Log::channel('mns')->error(sprintf('[publish_task] douyinShare ref=%s tenant=%d exception=%s at %s:%d',
                $reference, (int)$this->request->tenantId, get_class($e), basename($e->getFile()), $e->getLine()));
            $response = $this->fail('暂时无法准备抖音分享，请联系管理员并提供排查编号 ' . $reference);
        }
        return $response->header(['Cache-Control' => 'private, no-store']);
    }

    /** POST /api/publish_task/create：保存任务和首次执行时间。 */
    public function create()
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            $input = $this->request->post();
            // 全局 trim 会把 JSON 数字和布尔值转成字符串，员工 ID 需要按原始类型校验。
            if (array_key_exists('staff_ids', $input)) {
                $input['staff_ids'] = $this->request->post('staff_ids', null, null);
            }
            // 任务归属取自登录上下文，请求体只提供任务配置。
            foreach (['work_group_ids', 'platform_materials'] as $key) {
                if (array_key_exists($key, $input)) {
                    $input[$key] = $this->request->post($key, null, null);
                }
            }
            return $this->success('', PublishTaskService::create(
                (int)$this->request->tenantId, $this->userId, $input
            ));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            // 记录创建失败的原因，接口返回通用提示。
            Log::channel('mns')->error(sprintf('[publish_task] create failed tenant=%d user=%d type=%s',
                (int)$this->request->tenantId, $this->userId, get_class($e)));
            return $this->fail('任务发布失败，请稍后重试');
        }
    }

    /** GET /api/publish_task/lists：分页返回当前租户和用户的任务及最近执行结果。 */
    public function lists()
    {
        if (!$this->request->isGet()) {
            return $this->fail('请使用 GET 请求');
        }
        try {
            return $this->success('', PublishTaskService::lists(
                (int)$this->request->tenantId, $this->userId,
                (int)$this->request->get('page_no/d', 1), (int)$this->request->get('page_size/d', 20)
            ));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** GET /api/publish_task/runs：分页返回指定计划的执行记录。 */
    public function runs()
    {
        if (!$this->request->isGet()) {
            return $this->fail('请使用 GET 请求');
        }
        try {
            return $this->success('', PublishTaskService::runs(
                (int)$this->request->tenantId, $this->userId,
                (int)$this->request->get('task_id/d', 0),
                (int)$this->request->get('page_no/d', 1), (int)$this->request->get('page_size/d', 20)
            ));
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** POST /api/publish_task/pause：暂停发布计划。 */
    public function pause()
    {
        return $this->changePaused(true);
    }

    /** POST /api/publish_task/resume：恢复发布计划。 */
    public function resume()
    {
        return $this->changePaused(false);
    }

    private function changePaused(bool $paused)
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        try {
            PublishTaskService::setPaused(
                (int)$this->request->tenantId, $this->userId,
                (int)$this->request->post('task_id/d', 0), $paused
            );
            return $this->success();
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }
}
