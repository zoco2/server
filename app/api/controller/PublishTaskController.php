<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\publish_task\PublishTaskService;
use InvalidArgumentException;
use think\facade\Log;
use Throwable;

/** 提供当前登录用户的任务发布和查询接口，由框架默认路由分发。 */
final class PublishTaskController extends BaseApiController
{
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
