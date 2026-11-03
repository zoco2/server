<?php

namespace app\tenantapi\controller\smart_edit;

use app\common\model\smart_edit\SmartEditTask;
use app\common\service\smart_edit\SmartEditService;
use Exception;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\smart_edit\SmartEditTaskLists as AdminSmartEditTaskLists;

/**
 * 租户后台：剪辑任务管理。
 */
class AdminTaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new AdminSmartEditTaskLists());
    }

    public function detail()
    {
        try {
            $task = $this->findTask((int)$this->request->get('id', 0));
            SmartEditService::refresh((int)$task['id']);
            $task = $task->refresh();
            $row = SmartEditService::decorateRows([$task->toArray()])[0];
            return $this->success('获取成功', SmartEditService::formatTask($row));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function retry()
    {
        try {
            $task = $this->findTask((int)$this->request->post('id', 0));
            if ((string)$task['status'] !== SmartEditTask::STATUS_FAILED) {
                throw new Exception('只有失败的任务可以重试');
            }
            SmartEditService::retry($task);
            return $this->success('已重新提交', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            $this->findTask((int)$this->request->post('id', 0))->delete();
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 按 id + tenant_id 取任务，避免只依赖全局作用域。
     */
    private function findTask(int $id): SmartEditTask
    {
        $task = SmartEditTask::where('id', $id)
            ->where('tenant_id', (int)$this->request->tenantId)
            ->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return $task;
    }
}