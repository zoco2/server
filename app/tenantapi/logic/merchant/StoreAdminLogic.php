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

    public static function detail(int $id): array
    {
        $store = self::storeQuery($id)->findOrEmpty();
        if ($store->isEmpty()) {
            self::setError('门店不存在或无权限');
            return [];
        }

        $data = $store->toArray();
        $data['brand_name'] = MerchantBrand::query()
            ->where('id', $store->brand_id)
            ->value('name') ?? '';
        $data['location'] = '';
        if ($store->latitude !== null && $store->longitude !== null) {
            $data['location'] = rtrim(rtrim((string)$store->latitude, '0'), '.') . ','
                . rtrim(rtrim((string)$store->longitude, '0'), '.');
        }
        return $data;
    }

    public static function add(array $params): bool
    {
        try {
            Db::transaction(static function () use ($params): void {
                $brand = self::lockBrand((int)$params['brand_id']);
                MerchantStore::create(self::fields($params) + [
                    'brand_id' => $brand->id,
                    'user_id' => $brand->user_id,
                    'status' => 1,
                ]);
            });
            return true;
        } catch (Throwable $e) {
            self::setError($e instanceof DomainException ? $e->getMessage() : '门店保存失败');
            return false;
        }
    }

    public static function edit(array $params): bool
    {
        try {
            Db::transaction(static function () use ($params): void {
                $brand = array_key_exists('brand_id', $params)
                    ? self::lockBrand((int)$params['brand_id'])
                    : null;
                $store = self::storeQuery((int)$params['id'])->lock(true)->findOrEmpty();
                if ($store->isEmpty()) {
                    throw new DomainException('门店不存在或无权限');
                }

                $fields = self::fields($params);
                if ($brand !== null) {
                    $fields['brand_id'] = $brand->id;
                    $fields['user_id'] = $brand->user_id;
                    if ((int)$store->user_id !== (int)$brand->user_id) {
                        // 员工的所属用户随门店变更，保持品牌、门店、员工的归属一致。
                        MerchantStaff::update(['user_id' => $brand->user_id], ['store_id' => $store->id]);
                    }
                }
                $store->save($fields);
            });
            return true;
        } catch (Throwable $e) {
            self::setError($e instanceof DomainException ? $e->getMessage() : '门店保存失败');
            return false;
        }
    }

    public static function delete(int $id): bool
    {
        try {
            return Db::transaction(static function () use ($id): bool {
                $store = self::storeQuery($id)->lock(true)->findOrEmpty();
                if ($store->isEmpty()) {
                    throw new DomainException('门店不存在或无权限');
                }
                $staff = MerchantStaff::query()
                    ->where('store_id', $id)
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

    private static function storeQuery(int $id)
    {
        return MerchantStore::query()->where('id', $id);
    }

    private static function lockBrand(int $id): MerchantBrand
    {
        $brand = MerchantBrand::query()
            ->where('id', $id)
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
