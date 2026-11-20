<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\service\workbench\UserOverview;

final class WorkbenchController extends BaseApiController
{
    /** GET /api/workbench/overview */
    public function overview()
    {
        if (!$this->request->isGet()) {
            return $this->fail('请使用 GET 请求');
        }

        $tenantId = (int)($this->userInfo['tenant_id'] ?? 0);
        if ($this->userId <= 0 || $tenantId <= 0 || (int)$this->request->tenantId !== $tenantId) {
            return $this->fail('账号与租户不匹配，请重新登录');
        }

        return $this->data(UserOverview::build($tenantId, $this->userId))
            ->header(['Cache-Control' => 'private, no-store']);
    }
}
