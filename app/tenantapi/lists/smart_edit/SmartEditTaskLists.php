<?php

namespace app\tenantapi\lists\smart_edit;

use app\common\model\smart_edit\SmartEditTask;
use app\common\service\smart_edit\SmartEditService;
use app\tenantapi\lists\BaseAdminDataLists;

/**
 * 租户后台「剪辑任务」列表：可查看本租户全部用户的剪辑任务。
 */
class SmartEditTaskLists extends BaseAdminDataLists
{
    public function lists(): array
    {
        $rows = $this->query()
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        // 刷新会直接改数据库，$rows 是刷新前的快照，必须重读一次。
        if ($this->refreshRunning($rows)) {
            $rows = $this->query()
                ->whereIn('id', array_column($rows, 'id'))
                ->order('id', 'desc')
                ->select()
                ->toArray();
        }
        return SmartEditService::decorateRows($rows);
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    private function query()
    {
        $query = SmartEditTask::where('tenant_id', $this->request->tenantId);
        $status = trim((string)$this->request->get('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $editType = trim((string)$this->request->get('edit_type', ''));
        if ($editType !== '') {
            $query->where('edit_type', $editType);
        }
        $userId = (int)$this->request->get('user_id', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $taskNo = trim((string)$this->request->get('task_no', ''));
        if ($taskNo !== '') {
            $query->whereLike('task_no', '%' . $taskNo . '%');
        }
        return $query;
    }

    /**
     * @return bool 是否真的刷新过（刷新过则调用方需要重读数据）
     */
    private function refreshRunning(array $rows): bool
    {
        $refreshed = false;
        foreach ($rows as $row) {
            if (!in_array((string)$row['status'], SmartEditTask::RUNNING_STATUSES, true)) {
                continue;
            }
            if ((int)$row['next_poll_time'] > time()) {
                continue;
            }
            try {
                SmartEditService::refresh((int)$row['id']);
                $refreshed = true;
            } catch (\Throwable) {
                continue;
            }
        }
        return $refreshed;
    }
}