<?php

namespace app\tenantapi\logic\merchant;

use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\MerchantStore;
use DomainException;
use think\facade\Db;
use Throwable;

class StoreAdminLogic extends BaseLogic
{
    private const TEXT_FIELDS = [
        'name', 'phone', 'contact_name', 'business_hours', 'province', 'city', 'district',
        'address', 'poi_id', 'intro', 'logo_url',
    ];

    public static function detail(int $id, int $tenantId): array
    {
        $store = self::storeQuery($id, $tenantId)->findOrEmpty();
        if ($store->isEmpty()) {
            self::setError('门店不存在或无权限');
            return [];
        }

        $data = $store->toArray();
        $data['brand_name'] = MerchantBrand::where('tenant_id', $tenantId)
            ->where('id', $store->brand_id)
            ->whereNull('delete_time')
            ->value('name') ?? '';
        $data['location'] = '';
        if ($store->latitude !== null && $store->longitude !== null) {
            $data['location'] = rtrim(rtrim((string)$store->latitude, '0'), '.') . ','
                . rtrim(rtrim((string)$store->longitude, '0'), '.');
        }
        return $data;
    }

    public static function add(array $params, int $tenantId): bool
    {
        try {
            Db::transaction(static function () use ($params, $tenantId): void {
                $brand = self::lockBrand((int)$params['brand_id'], $tenantId);
                $now = time();
                MerchantStore::create(self::fields($params) + [
                    'tenant_id' => $tenantId,
                    'brand_id' => $brand->id,
                    'user_id' => $brand->user_id,
                    'status' => 1,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            });
            return true;
        } catch (Throwable $e) {
            self::setError($e instanceof DomainException ? $e->getMessage() : '门店保存失败');
            return false;
        }
    }

    public static function edit(array $params, int $tenantId): bool
    {
        try {
            Db::transaction(static function () use ($params, $tenantId): void {
                $brand = array_key_exists('brand_id', $params)
                    ? self::lockBrand((int)$params['brand_id'], $tenantId)
                    : null;
                $store = self::storeQuery((int)$params['id'], $tenantId)->lock(true)->findOrEmpty();
                if ($store->isEmpty()) {
                    throw new DomainException('门店不存在或无权限');
                }

                $fields = self::fields($params);
                $now = time();
                if ($brand !== null) {
                    $fields['brand_id'] = $brand->id;
                    $fields['user_id'] = $brand->user_id;
                    if ((int)$store->user_id !== (int)$brand->user_id) {
                        // 员工的所属用户随门店变更，保持品牌、门店、员工的归属一致。
                        MerchantStaff::where('tenant_id', $tenantId)
                            ->where('store_id', $store->id)
                            ->whereNull('delete_time')
                            ->update(['user_id' => $brand->user_id, 'update_time' => $now]);
                    }
                }
                self::storeQuery((int)$store->id, $tenantId)->update($fields + ['update_time' => $now]);
            });
            return true;
        } catch (Throwable $e) {
            self::setError($e instanceof DomainException ? $e->getMessage() : '门店保存失败');
            return false;
        }
    }

    public static function delete(int $id, int $tenantId): bool
    {
        try {
            return Db::transaction(static function () use ($id, $tenantId): bool {
                $store = self::storeQuery($id, $tenantId)->lock(true)->findOrEmpty();
                if ($store->isEmpty()) {
                    throw new DomainException('门店不存在或无权限');
                }
                $staff = MerchantStaff::where('tenant_id', $tenantId)
                    ->where('store_id', $id)
                    ->whereNull('delete_time')
                    ->field('id')
                    ->lock(true)
                    ->findOrEmpty();
                if (!$staff->isEmpty()) {
                    throw new DomainException('请先删除该门店下的员工');
                }
                if (!$store->delete()) {
                    throw new DomainException('门店删除失败');
                }
                return true;
            });
        } catch (Throwable $e) {
            self::setError($e instanceof DomainException ? $e->getMessage() : '门店删除失败');
            return false;
        }
    }

    private static function storeQuery(int $id, int $tenantId)
    {
        return MerchantStore::where('tenant_id', $tenantId)->where('id', $id)->whereNull('delete_time');
    }

    private static function lockBrand(int $id, int $tenantId): MerchantBrand
    {
        $brand = MerchantBrand::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->whereNull('delete_time')
            ->field('id,user_id')
            ->lock(true)
            ->findOrEmpty();
        if ($brand->isEmpty()) {
            throw new DomainException('所属品牌不存在或无权限');
        }
        return $brand;
    }

    private static function fields(array $params): array
    {
        $fields = array_map(
            static fn ($value): string => trim((string)$value),
            array_intersect_key($params, array_flip(self::TEXT_FIELDS))
        );
        if ($fields['name'] === '') {
            throw new DomainException('请填写门店名称');
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $fields['status'] = (int)$params['status'];
        }
        $location = trim((string)($params['location'] ?? ''));
        if ($location !== '' || array_key_exists('latitude', $params) || array_key_exists('longitude', $params)) {
            [$fields['latitude'], $fields['longitude']] = self::coordinates($params, $location);
        }
        return $fields;
    }

    private static function coordinates(array $params, string $location): array
    {
        if ($location !== '') {
            [$latitude, $longitude] = array_map('trim', explode(',', $location, 2));
        } else {
            $latitude = $params['latitude'] ?? '';
            $longitude = $params['longitude'] ?? '';
        }
        if ($latitude === '' && $longitude === '') {
            return [null, null];
        }
        if ($latitude === '' || $longitude === '') {
            throw new DomainException('请同时填写纬度和经度');
        }
        return [number_format((float)$latitude, 7, '.', ''), number_format((float)$longitude, 7, '.', '')];
    }
}
