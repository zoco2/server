<?php

namespace app\api\lists\smart_edit;

use app\api\lists\BaseApiDataLists;
use app\common\model\smart_edit\SmartEditTask;
use app\common\service\smart_edit\SmartEditService;

/**
 * 用户端「我的剪辑任务」列表。
 */
class SmartEditTaskLists extends BaseApiDataLists
{
    public function lists(): array
    {
        $rows = $this->query()
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        // 刷新会直接改数据库，$rows 是刷新前的快照，必须重读一次，
        // 否则任务已经跑完，列表返回的仍然是「剪辑中」。
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
        $query = SmartEditTask::where('user_id', $this->userId);
        // 显式带上 tenant_id：取不到时（0）不加条件，交给全局作用域，
        // 否则会把平台侧任务全部过滤掉。
        $tenantId = (int)$this->request->tenantId;
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $status = trim((string)$this->request->get('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $editType = trim((string)$this->request->get('edit_type', ''));
        if ($editType !== '') {
            $query->where('edit_type', $editType);
        }
        return $query;
    }

    /**
     * 打开列表时顺手刷新临期任务，避免必须等 worker。
     *
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