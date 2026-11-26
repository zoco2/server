<?php

namespace app\tenantapi\lists\merchant;

use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantStore;
use app\tenantapi\lists\BaseAdminDataLists;

class StoreLists extends BaseAdminDataLists
{
    public function lists(): array
    {
        $stores = $this->query()
            ->field('id,brand_id,name,status,phone,contact_name,business_hours,province,city,district,'
                . 'address,latitude,longitude,poi_id,intro,logo_url,create_time,update_time')
            ->order('id', 'desc')
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        if (!$stores) {
            return [];
        }

        $names = MerchantBrand::where('tenant_id', $this->adminInfo['tenant_id'])
            ->whereNull('delete_time')
            ->whereIn('id', array_unique(array_column($stores, 'brand_id')))
            ->column('name', 'id');
        foreach ($stores as &$store) {
            $store['brand_name'] = $names[$store['brand_id']] ?? '';
        }
        return $stores;
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    private function query()
    {
        $query = MerchantStore::where('tenant_id', $this->adminInfo['tenant_id'])
            ->whereNull('delete_time');
        $keyword = trim((string)($this->params['keyword'] ?? ''));
        if ($keyword !== '') {
            $escaped = strtr($keyword, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
            $query->whereLike('name|contact_name|phone', '%' . $escaped . '%');
        }
        foreach (['brand_id', 'status', 'province', 'city'] as $field) {
            if (isset($this->params[$field]) && $this->params[$field] !== '') {
                $query->where($field, in_array($field, ['brand_id', 'status'], true)
                    ? (int)$this->params[$field]
                    : trim((string)$this->params[$field]));
            }
        }
        return $query;
    }
}
