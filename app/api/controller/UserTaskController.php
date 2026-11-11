<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\user_task\UserTaskPlanService;
use InvalidArgumentException;

final class UserTaskController extends BaseApiController
{
    /** 创建计划。日期字段以计划时区解释，兼容 Unix 秒格式。 */
    public function add()
    {
        return $this->write(fn () => UserTaskPlanService::create(
            (int)$this->request->tenantId, $this->userId, $this->request->post()
        ));
    }

    /** 预览有效期内最近三个未来执行时间。 */
    public function preview()
    {
        return $this->write(fn () => UserTaskPlanService::preview($this->request->post()));
    }

    public function lists()
    {
        return $this->respond(fn () => UserTaskPlanService::lists(
            (int)$this->request->tenantId,
            $this->userId,
            (int)$this->request->get('page_no/d', 1),
            (int)$this->request->get('page_size/d', 20)
        ));
    }

    /** 按计划读取当前用户的执行历史，删除计划后历史仍可查询。 */
    public function runs()
    {
        return $this->respond(fn () => UserTaskPlanService::runs(
            (int)$this->request->tenantId,
            $this->userId,
            (int)$this->request->get('id/d', 0),
            (int)$this->request->get('page_no/d', 1),
            (int)$this->request->get('page_size/d', 20)
        ));
    }

    public function pause()
    {
        return $this->write(fn () => UserTaskPlanService::pause(
            (int)$this->request->tenantId, $this->userId, (int)$this->request->post('id/d', 0)
        ));
    }

    public function resume()
    {
        return $this->write(fn () => UserTaskPlanService::resume(
            (int)$this->request->tenantId, $this->userId, (int)$this->request->post('id/d', 0)
        ));
    }

    public function delete()
    {
        return $this->write(fn () => UserTaskPlanService::delete(
            (int)$this->request->tenantId, $this->userId, (int)$this->request->post('id/d', 0)
        ));
    }

    private function write(callable $action)
    {
        if (!$this->request->isPost()) {
            return $this->fail('请使用 POST 请求');
        }
        return $this->respond($action);
    }

    private function respond(callable $action)
    {
        try {
            return $this->success('', $action() ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }
}
