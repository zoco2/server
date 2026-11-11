<?php

namespace app\common\job;

use app\common\service\geo\GeoArticleGenerationService;

class GeoArticleGenerationJob extends Job
{
    public function handle(): void
    {
        GeoArticleGenerationService::run((int)$this->tenantId, (string)($this->data['request_id'] ?? ''));
    }
}
