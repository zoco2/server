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
        $this->refreshRunning($rows);
        return SmartEditService::decorateRows($rows);
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    private function query()
    {
        $query = SmartEditTask::where('user_id', $this->userId);
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
     */
    private function refreshRunning(array $rows): void
    {
        foreach ($rows as $row) {
            if (!in_array((string)$row['status'], SmartEditTask::RUNNING_STATUSES, true)) {
                continue;
            }
            if ((int)$row['next_poll_time'] > time()) {
                continue;
            }
            try {
                SmartEditService::refresh((int)$row['id']);
            } catch (\Throwable) {
                continue;
            }
        }
    }
}