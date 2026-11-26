<?php

namespace app\tenantapi\lists\merchant;

use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\MerchantStore;
use app\tenantapi\lists\BaseAdminDataLists;

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
        $stores = MerchantStore::whereIn('brand_id', $ids)
            ->field('id,brand_id,user_id')
            ->select()
            ->toArray();
        $storesByBrand = array_count_values(array_column($stores, 'brand_id'));
        $storesById = array_column($stores, null, 'id');
        $staffByBrand = [];
        if ($storesById) {
            $staffCounts = MerchantStaff::whereIn('store_id', array_keys($storesById))
                ->fieldRaw('store_id,user_id,COUNT(*) AS total')
                ->group('store_id,user_id')
                ->select()
                ->toArray();
            foreach ($staffCounts as $count) {
                $store = $storesById[$count['store_id']];
                if ((int)$store['user_id'] === (int)$count['user_id']) {
                    $brandId = $store['brand_id'];
                    $staffByBrand[$brandId] = ($staffByBrand[$brandId] ?? 0) + (int)$count['total'];
                }
            }
        }

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
        $query = MerchantBrand::query();
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
