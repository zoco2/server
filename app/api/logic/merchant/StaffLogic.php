<?php

namespace app\api\logic\merchant;

use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\MerchantStore;
use Throwable;

/**
 * 门店员工保存与查询。不创建登录账号，公众号身份留空。
 */
class StaffLogic extends BaseLogic
{
    /**
     * @param int $tenantId
     * @param int $userId
     * @param array $params
     * @return array
     */
    public static function lists(int $tenantId, int $userId, array $params): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = self::limit($params);
        $query = MerchantScope::query(MerchantStaff::class, $tenantId, $userId);
        if ((int)($params['store_id'] ?? 0) > 0) {
            $query->where('store_id', (int)$params['store_id']);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $safe = str_replace(['%', '_'], ['\%', '\_'], $keyword);
            $query->where(function ($inner) use ($safe) {
                $inner->whereLike('name', '%' . $safe . '%')->whereOr('mobile', 'like', '%' . $safe . '%');
            });
        }
        $count = (clone $query)->count();
        $lists = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return ['lists' => $lists, 'count' => $count, 'page' => $page, 'limit' => $limit];
    }

    /**
     * @param int $tenantId
     * @param int $userId
     * @param int $id
     * @return array
     */
    public static function detail(int $tenantId, int $userId, int $id): array
    {
        $row = MerchantScope::query(MerchantStaff::class, $tenantId, $userId)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('员工不存在或无权限');
            return [];
        }
        return $row->toArray();
    }

    /**
     * @param int $tenantId
     * @param int $userId
     * @param array $params
     * @return bool
     */
    public static function add(int $tenantId, int $userId, array $params): bool
    {
        $storeId = (int)$params['store_id'];
        if (!self::storeOwned($tenantId, $userId, $storeId)) {
            self::setError('所属门店不存在或无权限');
            return false;
        }
        if (self::mobileTaken($tenantId, $userId, $storeId, $params['mobile'])) {
            self::setError('该门店已有此手机号');
            return false;
        }
        try {
            MerchantStaff::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'store_id' => $storeId,
                'name' => trim((string)$params['name']),
                'mobile' => trim((string)$params['mobile']),
                'appid' => null,
                'openid' => null,
                'create_time' => time(),
                'update_time' => time(),
                'delete_time' => 0,
            ]);
            return true;
        } catch (Throwable $e) {
            self::setError(str_contains($e->getMessage(), 'uk_store_mobile') ? '该门店已有此手机号' : '员工保存失败');
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
        $row = MerchantScope::query(MerchantStaff::class, $tenantId, $userId)->where('id', (int)$params['id'])->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('员工不存在或无权限');
            return false;
        }
        $storeId = (int)$params['store_id'];
        if (!self::storeOwned($tenantId, $userId, $storeId)) {
            self::setError('所属门店不存在或无权限');
            return false;
        }
        if (self::mobileTaken($tenantId, $userId, $storeId, $params['mobile'], (int)$row['id'])) {
            self::setError('该门店已有此手机号');
            return false;
        }
        try {
            $row->save([
                'store_id' => $storeId,
                'name' => trim((string)$params['name']),
                'mobile' => trim((string)$params['mobile']),
                'update_time' => time(),
            ]);
            return true;
        } catch (Throwable $e) {
            self::setError(str_contains($e->getMessage(), 'uk_store_mobile') ? '该门店已有此手机号' : '员工保存失败');
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
        $row = MerchantScope::query(MerchantStaff::class, $tenantId, $userId)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('员工不存在或无权限');
            return false;
        }
        $now = time();
        $row->save([
            'delete_time' => $now,
            'update_time' => $now,
            'appid' => null,
            'openid' => null,
        ]);
        return true;
    }

    /**
     * @param int $tenantId
     * @param int $userId
     * @param int $storeId
     * @return bool
     */
    private static function storeOwned(int $tenantId, int $userId, int $storeId): bool
    {
        return MerchantScope::query(MerchantStore::class, $tenantId, $userId)->where('id', $storeId)->count() > 0;
    }

    /**
     * @param int $tenantId
     * @param int $userId
     * @param int $storeId
     * @param string $mobile
     * @param int $exceptId
     * @return bool
     */
    private static function mobileTaken(int $tenantId, int $userId, int $storeId, string $mobile, int $exceptId = 0): bool
    {
        $query = MerchantScope::query(MerchantStaff::class, $tenantId, $userId)
            ->where('store_id', $storeId)
            ->where('mobile', trim($mobile));
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        return $query->count() > 0;
    }

    /**
     * @param array $params
     * @return int
     */
    private static function limit(array $params): int
    {
        $limit = (int)($params['limit'] ?? 20);
        return ($limit >= 1 && $limit <= 50) ? $limit : 20;
    }
}
