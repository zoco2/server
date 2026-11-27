<?php

declare(strict_types=1);

namespace app\tenantapi\controller\merchant;

use app\common\cache\TenantAdminAuthCache;
use app\common\service\merchant\NfcCodeService;
use app\tenantapi\controller\BaseAdminController;
use DomainException;
use think\helper\Str;

class NfcCodeController extends BaseAdminController
{
    public function lists()
    {
        if (($this->adminInfo['root'] ?? null) !== 1) {
            $permissions = (new TenantAdminAuthCache($this->adminId))->getAdminUri() ?? [];
            $normalize = static fn (string $uri): string => strtolower(Str::camel($uri));
            if (!in_array($normalize('merchant.nfc_code/lists'), array_map($normalize, $permissions), true)) {
                return $this->fail('权限不足，无法访问或操作');
            }
        }

        try {
            return $this->data(NfcCodeService::tenantLists($this->request->get()));
        } catch (DomainException $error) {
            return $this->fail($error->getMessage());
        }
    }
}
