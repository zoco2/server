<?php

namespace app\api\logic\merchant;

use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantStore;
use Throwable;

/**
 * 品牌保存与查询。
 */
class BrandLogic extends BaseLogic
{
    /**
     * @return array
     */
    public static function choices(): array
    {
        return MerchantScope::query(MerchantBrand::class)
            ->field('id,name')
            ->order('id', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * @param int $id
     * @return array
     */
    public static function detail(int $id): array
    {
        $row = MerchantScope::query(MerchantBrand::class)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('品牌不存在或无权限');
            return [];
        }
        return $row->toArray();
    }

    /**
     * @param array $params
     * @return bool
     */
    public static function add(array $params): bool
    {
        if (self::nameTaken($params['name'])) {
            self::setError('品牌名称已存在');
            return false;
        }
        try {
            MerchantBrand::create(self::fields($params));
            return true;
        } catch (Throwable $e) {
            self::setError(self::duplicate($e, '品牌名称已存在') ? '品牌名称已存在' : '品牌保存失败');
            return false;
        }
    }

    /**
     * @param array $params
     * @return bool
     */
    public static function edit(array $params): bool
    {
        $id = (int)$params['id'];
        $row = MerchantScope::query(MerchantBrand::class)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('品牌不存在或无权限');
            return false;
        }
        if (self::nameTaken($params['name'], $id)) {
            self::setError('品牌名称已存在');
            return false;
        }
        try {
            $row->save(self::fields($params));
            return true;
        } catch (Throwable $e) {
            self::setError(self::duplicate($e, '品牌名称已存在') ? '品牌名称已存在' : '品牌保存失败');
            return false;
        }
    }

    /**
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        $row = MerchantScope::query(MerchantBrand::class)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('品牌不存在或无权限');
            return false;
        }
        $storeCount = MerchantScope::query(MerchantStore::class)->where('brand_id', $id)->count();
        if ($storeCount > 0) {
            self::setError('请先删除该品牌下的门店');
            return false;
        }
        $row->delete();
        return true;
    }

    /**
     * @param array $params
     * @return array
     */
    private static function fields(array $params): array
    {
        return [
            'user_id' => (int)request()->userId,
            'name' => trim((string)$params['name']),
            'industry' => trim((string)($params['industry'] ?? '')),
            'contact_name' => trim((string)($params['contact_name'] ?? '')),
            'contact_phone' => trim((string)($params['contact_phone'] ?? '')),
            'contact_email' => trim((string)($params['contact_email'] ?? '')),
            'website' => trim((string)($params['website'] ?? '')),
            'address' => trim((string)($params['address'] ?? '')),
            'logo_url' => trim((string)($params['logo_url'] ?? '')),
            'cover_url' => trim((string)($params['cover_url'] ?? '')),
        ];
    }

    /**
     * @param string $name
     * @param int $exceptId
     * @return bool
     */
    private static function nameTaken(string $name, int $exceptId = 0): bool
    {
        $query = MerchantScope::query(MerchantBrand::class)->where('name', trim($name));
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        return $query->count() > 0;
    }

    /**
     * @param Throwable $e
     * @param string $needle
     * @return bool
     */
    private static function duplicate(Throwable $e, string $needle): bool
    {
        return str_contains($e->getMessage(), 'uk_owner_name') || str_contains($e->getMessage(), $needle);
    }
}
