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
     * @param array $params
     * @return array
     */
    public static function lists(array $params): array
    {
        $page = max(1, (int)($params['page_no'] ?? 1));
        $limit = self::pageSize($params);
        $query = MerchantScope::query(MerchantStaff::class);
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
        return ['lists' => $lists, 'count' => $count, 'page_no' => $page, 'page_size' => $limit];
    }

    /**
     * @param int $id
     * @return array
     */
    public static function detail(int $id): array
    {
        $row = MerchantScope::query(MerchantStaff::class)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('员工不存在或无权限');
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
        $storeId = (int)$params['store_id'];
        if (!self::storeOwned($storeId)) {
            self::setError('所属门店不存在或无权限');
            return false;
        }
        if (self::mobileTaken($storeId, $params['mobile'])) {
            self::setError('该门店已有此手机号');
            return false;
        }
        try {
            MerchantStaff::create([
                'user_id' => (int)request()->userId,
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
     * @param array $params
     * @return bool
     */
    public static function edit(array $params): bool
    {
        $row = MerchantScope::query(MerchantStaff::class)->where('id', (int)$params['id'])->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('员工不存在或无权限');
            return false;
        }
        $storeId = (int)$params['store_id'];
        if (!self::storeOwned($storeId)) {
            self::setError('所属门店不存在或无权限');
            return false;
        }
        if (self::mobileTaken($storeId, $params['mobile'], (int)$row['id'])) {
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
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        $row = MerchantScope::query(MerchantStaff::class)->where('id', $id)->findOrEmpty();
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
     * @param int $storeId
     * @return bool
     */
    private static function storeOwned(int $storeId): bool
    {
        return MerchantScope::query(MerchantStore::class)->where('id', $storeId)->count() > 0;
    }

    /**
     * @param int $storeId
     * @param string $mobile
     * @param int $exceptId
     * @return bool
     */
    private static function mobileTaken(int $storeId, string $mobile, int $exceptId = 0): bool
    {
        $query = MerchantScope::query(MerchantStaff::class)
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
    private static function pageSize(array $params): int
    {
        $limit = (int)($params['page_size'] ?? 20);
        return ($limit >= 1 && $limit <= 50) ? $limit : 20;
    }
}
