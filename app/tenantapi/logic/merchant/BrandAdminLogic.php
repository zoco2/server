<?php

namespace app\tenantapi\logic\merchant;

use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\MerchantStore;
use Throwable;

class BrandAdminLogic extends BaseLogic
{
    public static function detail(int $id): array
    {
        $brand = MerchantBrand::query()
            ->where('id', $id)
            ->whereNull('delete_time')
            ->findOrEmpty();
        if ($brand->isEmpty()) {
            self::setError('品牌不存在或无权限');
            return [];
        }

        $data = $brand->toArray();
        foreach (array_keys(MerchantBrand::SECTION_LABELS) as $field) {
            $data[$field] = (string)($data[$field] ?? '');
        }
        $stores = MerchantStore::query()
            ->where('brand_id', $id)
            ->where('user_id', $brand->user_id)
            ->whereNull('delete_time')
            ->field('id,name,status,contact_name,phone,address')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $staffByStore = [];
        if ($stores) {
            $counts = MerchantStaff::query()
                ->where('user_id', $brand->user_id)
                ->whereIn('store_id', array_column($stores, 'id'))
                ->whereNull('delete_time')
                ->fieldRaw('store_id, COUNT(*) AS total')
                ->group('store_id')
                ->select()
                ->toArray();
            $staffByStore = array_column($counts, 'total', 'store_id');
        }
        foreach ($stores as &$store) {
            $store['staff_count'] = (int)($staffByStore[$store['id']] ?? 0);
        }
        $data['stores'] = $stores;
        $data['staff_count'] = array_sum(array_column($stores, 'staff_count'));
        return $data;
    }

    public static function edit(array $params): bool
    {
        $brand = MerchantBrand::query()
            ->where('id', (int)$params['id'])
            ->whereNull('delete_time')
            ->findOrEmpty();
        if ($brand->isEmpty()) {
            self::setError('品牌不存在或无权限');
            return false;
        }

        $allowed = [...MerchantBrand::PROFILE_FIELDS, ...array_keys(MerchantBrand::SECTION_LABELS)];
        $fields = array_map(
            static fn ($value): string => trim((string)$value),
            array_intersect_key($params, array_flip($allowed))
        );
        if ($fields['name'] === '') {
            self::setError('请填写品牌名称');
            return false;
        }
        if (MerchantBrand::query()
            ->where('user_id', $brand->user_id)
            ->where('name', $fields['name'])
            ->where('id', '<>', $brand->id)
            ->whereNull('delete_time')
            ->count() > 0) {
            self::setError('品牌名称已存在');
            return false;
        }

        try {
            MerchantBrand::query()
                ->where('id', $brand->id)
                ->whereNull('delete_time')
                ->update($fields + ['update_time' => time()]);
            return true;
        } catch (Throwable $e) {
            self::setError(str_contains($e->getMessage(), 'uk_owner_name') ? '品牌名称已存在' : '品牌保存失败');
            return false;
        }
    }
}
