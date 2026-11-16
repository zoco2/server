<?php

namespace app\common\job;

use app\common\service\geo\GeoArticleGenerationService;
use app\common\service\user_task\GeoRankingTaskJob;

class GeoArticleGenerationJob extends Job
{
    public function handle(): void
    {
        $tenantId = (int)$this->tenantId;
        $requestId = (string)($this->data['request_id'] ?? '');
        GeoArticleGenerationService::run($tenantId, $requestId);
        $taskRunId = (int)($this->data['task_run_id'] ?? 0);
        if ($taskRunId > 0) {
            $userId = (int)$this->data['user_id'];
            $result = GeoArticleGenerationService::detail($tenantId, $userId, $requestId);
            GeoRankingTaskJob::onGenerationFinished($taskRunId, $tenantId, $userId, $result);
        }
    }
}
