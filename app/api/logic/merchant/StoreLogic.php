<?php

namespace app\api\logic\merchant;

use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantStore;
use Throwable;

/**
 * 门店保存与查询。
 */
class StoreLogic extends BaseLogic
{
    /**
     * @param int $tenantId
     * @param int $userId
     * @param array $params
     * @return array
     */
    public static function lists(int $tenantId, int $userId, array $params): array
    {
        $page = max(1, (int)($params['page_no'] ?? 1));
        $limit = self::pageSize($params);
        $query = MerchantScope::query(MerchantStore::class, $tenantId, $userId);
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name', '%' . self::like($keyword) . '%');
        }
        if ((int)($params['brand_id'] ?? 0) > 0) {
            $query->where('brand_id', (int)$params['brand_id']);
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }
        $count = (clone $query)->count();
        $lists = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return ['lists' => $lists, 'count' => $count, 'page_no' => $page, 'page_size' => $limit];
    }

    /**
     * @param int $tenantId
     * @param int $userId
     * @param int $brandId
     * @return array
     */
    public static function choices(int $tenantId, int $userId, int $brandId = 0): array
    {
        $query = MerchantScope::query(MerchantStore::class, $tenantId, $userId)->field('id,brand_id,name');
        if ($brandId > 0) {
            $query->where('brand_id', $brandId);
        }
        return $query->order('id', 'desc')->select()->toArray();
    }

    /**
     * @param int $tenantId
     * @param int $userId
     * @param int $id
     * @return array
     */
    public static function detail(int $tenantId, int $userId, int $id): array
    {
        $row = MerchantScope::query(MerchantStore::class, $tenantId, $userId)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('门店不存在或无权限');
            return [];
        }
        $data = $row->toArray();
        $data['location'] = self::locationText($data['latitude'] ?? null, $data['longitude'] ?? null);
        return $data;
    }

    /**
     * @param int $tenantId
     * @param int $userId
     * @param array $params
     * @return bool
     */
    public static function add(int $tenantId, int $userId, array $params): bool
    {
        if (!self::brandOwned($tenantId, $userId, (int)$params['brand_id'])) {
            self::setError('所属品牌不存在或无权限');
            return false;
        }
        try {
            MerchantStore::create(self::fields($tenantId, $userId, $params, true));
            return true;
        } catch (Throwable $e) {
            self::setError($e instanceof \InvalidArgumentException ? $e->getMessage() : '门店保存失败');
            return false;
        }
    }

    /**
     * @param int $tenantId
     * @param int $userId
     * @param array $params
     * @return bool
     */
    public static function edit(int $tenantId, int $userId, array $params): bool
    {
        $row = MerchantScope::query(MerchantStore::class, $tenantId, $userId)->where('id', (int)$params['id'])->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('门店不存在或无权限');
            return false;
        }
        if (!self::brandOwned($tenantId, $userId, (int)$params['brand_id'])) {
            self::setError('所属品牌不存在或无权限');
            return false;
        }
        try {
            $fields = self::fields($tenantId, $userId, $params, false);
            unset($fields['create_time'], $fields['delete_time']);
            $row->save($fields);
            return true;
        } catch (Throwable $e) {
            self::setError($e instanceof \InvalidArgumentException ? $e->getMessage() : '门店保存失败');
            return false;
        }
    }

    /**
     * @param int $tenantId
     * @param int $userId
     * @param int $id
     * @return bool
     */
    public static function delete(int $tenantId, int $userId, int $id): bool
    {
        $row = MerchantScope::query(MerchantStore::class, $tenantId, $userId)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('门店不存在或无权限');
            return false;
        }
        $now = time();
        $row->save(['delete_time' => $now, 'update_time' => $now]);
        return true;
    }

    /**
     * @param int $tenantId
     * @param int $userId
     * @param int $brandId
     * @return bool
     */
    private static function brandOwned(int $tenantId, int $userId, int $brandId): bool
    {
        return MerchantScope::query(MerchantBrand::class, $tenantId, $userId)->where('id', $brandId)->count() > 0;
    }

    /**
     * @param int $tenantId
     * @param int $userId
     * @param array $params
     * @param bool $creating
     * @return array
     */
    private static function fields(int $tenantId, int $userId, array $params, bool $creating): array
    {
        [$latitude, $longitude] = self::coordinates($params);
        $fields = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'brand_id' => (int)$params['brand_id'],
            'name' => trim((string)$params['name']),
            'status' => isset($params['status']) && $params['status'] !== '' ? (int)$params['status'] : 1,
            'phone' => trim((string)($params['phone'] ?? '')),
            'contact_name' => trim((string)($params['contact_name'] ?? '')),
            'business_hours' => trim((string)($params['business_hours'] ?? '')),
            'province' => trim((string)($params['province'] ?? '')),
            'city' => trim((string)($params['city'] ?? '')),
            'district' => trim((string)($params['district'] ?? '')),
            'address' => trim((string)($params['address'] ?? '')),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'poi_id' => trim((string)($params['poi_id'] ?? '')),
            'intro' => trim((string)($params['intro'] ?? '')),
            'logo_url' => trim((string)($params['logo_url'] ?? '')),
            'update_time' => time(),
        ];
        if ($creating) {
            $fields['create_time'] = time();
            $fields['delete_time'] = 0;
        }
        return $fields;
    }

    /**
     * @param array $params
     * @return array{0:?string,1:?string}
     */
    private static function coordinates(array $params): array
    {
        $location = trim((string)($params['location'] ?? ''));
        if ($location !== '') {
            $parts = array_map('trim', explode(',', $location, 2));
            return [self::scale($parts[0]), self::scale($parts[1])];
        }
        $latitude = $params['latitude'] ?? null;
        $longitude = $params['longitude'] ?? null;
        if (($latitude === '' || $latitude === null) && ($longitude === '' || $longitude === null)) {
            return [null, null];
        }
        if ($latitude === '' || $latitude === null || $longitude === '' || $longitude === null) {
            throw new \InvalidArgumentException('请同时填写纬度和经度');
        }
        return [self::scale($latitude), self::scale($longitude)];
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function scale($value): string
    {
        return number_format((float)$value, 7, '.', '');
    }

    /**
     * @param mixed $latitude
     * @param mixed $longitude
     * @return string
     */
    private static function locationText($latitude, $longitude): string
    {
        if ($latitude === null || $latitude === '' || $longitude === null || $longitude === '') {
            return '';
        }
        return rtrim(rtrim(number_format((float)$latitude, 7, '.', ''), '0'), '.')
            . ','
            . rtrim(rtrim(number_format((float)$longitude, 7, '.', ''), '0'), '.');
    }

    /**
     * @param array $params
     * @return int
     */
    private static function pageSize(array $params): int
    {
        $limit = (int)($params['page_size'] ?? 20);
        return ($limit >= 1 && $limit <= 50) ? $limit : 20;
    }

    /**
     * @param string $keyword
     * @return string
     */
    private static function like(string $keyword): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $keyword);
    }
}
