<?php

namespace app\tenantapi\lists\merchant;

use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\MerchantStore;
use app\tenantapi\lists\BaseAdminDataLists;

class StaffLists extends BaseAdminDataLists
{
    public function lists(): array
    {
        $staff = $this->query()->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()->toArray();
        if (!$staff) {
            return [];
        }

        $stores = MerchantStore::query()
            ->whereNull('delete_time')
            ->whereIn('id', array_unique(array_column($staff, 'store_id')))
            ->column('name,brand_id', 'id');
        $brandIds = array_unique(array_column($stores, 'brand_id'));
        $brands = $brandIds ? MerchantBrand::query()
            ->whereNull('delete_time')->whereIn('id', $brandIds)->column('name', 'id') : [];
        foreach ($staff as &$row) {
            $store = $stores[$row['store_id']] ?? null;
            $row['store_name'] = $store['name'] ?? '';
            $row['brand_name'] = $brands[$store['brand_id'] ?? 0] ?? '';
        }
        return $staff;
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    private function query()
    {
        $query = MerchantStaff::query()->whereNull('delete_time');
        $keyword = trim((string)($this->params['keyword'] ?? ''));
        if ($keyword !== '') {
            $escaped = strtr($keyword, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
            $query->whereLike('name|mobile', '%' . $escaped . '%');
        }
        if ((int)($this->params['store_id'] ?? 0) > 0) {
            $query->where('store_id', (int)$this->params['store_id']);
        }
        if ((int)($this->params['brand_id'] ?? 0) > 0) {
            $storeIds = MerchantStore::query()
                ->where('brand_id', (int)$this->params['brand_id'])
                ->whereNull('delete_time')->column('id');
            $query->whereIn('store_id', $storeIds ?: [0]);
        }
        return $query;
    }
}
