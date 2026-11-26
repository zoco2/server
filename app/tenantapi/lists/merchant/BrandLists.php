<?php

namespace app\tenantapi\lists\merchant;

use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantStore;
use app\tenantapi\lists\BaseAdminDataLists;
use think\facade\Db;

class BrandLists extends BaseAdminDataLists
{
    public function lists(): array
    {
        $brands = $this->query()
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        if (!$brands) {
            return [];
        }

        $ids = array_column($brands, 'id');
        $storeCounts = MerchantStore::where('tenant_id', $this->adminInfo['tenant_id'])
            ->whereIn('brand_id', $ids)
            ->whereNull('delete_time')
            ->fieldRaw('brand_id, COUNT(*) AS total')
            ->group('brand_id')
            ->select()
            ->toArray();
        $staffCounts = Db::name('merchant_store')->alias('store')
            ->join('merchant_staff staff', 'staff.store_id = store.id AND staff.user_id = store.user_id')
            ->where('store.tenant_id', $this->adminInfo['tenant_id'])
            ->where('staff.tenant_id', $this->adminInfo['tenant_id'])
            ->whereIn('store.brand_id', $ids)
            ->whereNull('store.delete_time')
            ->whereNull('staff.delete_time')
            ->fieldRaw('store.brand_id, COUNT(*) AS total')
            ->group('store.brand_id')
            ->select()
            ->toArray();

        $storesByBrand = array_column($storeCounts, 'total', 'brand_id');
        $staffByBrand = array_column($staffCounts, 'total', 'brand_id');
        foreach ($brands as &$brand) {
            $brand['store_count'] = (int)($storesByBrand[$brand['id']] ?? 0);
            $brand['staff_count'] = (int)($staffByBrand[$brand['id']] ?? 0);
        }
        return $brands;
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    private function query()
    {
        $query = MerchantBrand::where('tenant_id', $this->adminInfo['tenant_id'])
            ->whereNull('delete_time');
        $keyword = trim((string)($this->params['keyword'] ?? ''));
        if ($keyword !== '') {
            $escaped = strtr($keyword, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
            $query->whereLike('name|contact_name|contact_phone', '%' . $escaped . '%');
        }
        if (isset($this->params['user_id']) && $this->params['user_id'] !== '') {
            $query->where('user_id', (int)$this->params['user_id']);
        }
        return $query;
    }
}
