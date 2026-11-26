<?php

namespace app\tenantapi\logic\merchant;

use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\MerchantStore;
use DomainException;
use think\facade\Db;
use Throwable;

class StaffAdminLogic extends BaseLogic
{
    private const DUPLICATE_MOBILE = '该门店已存在相同手机号的员工';

    public static function detail(int $id, int $tenantId): array
    {
        $staff = self::staffQuery($id, $tenantId)->findOrEmpty();
        if ($staff->isEmpty()) {
            self::setError('员工不存在或无权限');
            return [];
        }
        return $staff->toArray();
    }

    public static function add(array $params, int $tenantId): bool
    {
        return self::persist($params, $tenantId, null);
    }

    public static function edit(array $params, int $tenantId): bool
    {
        return self::persist($params, $tenantId, (int)$params['id']);
    }

    private static function persist(array $params, int $tenantId, ?int $id): bool
    {
        try {
            Db::transaction(static function () use ($params, $tenantId, $id): void {
                $storeId = (int)$params['store_id'];
                // 锁住目标门店，串行处理同一门店的员工变更，并与门店删除互斥。
                $store = MerchantStore::where('tenant_id', $tenantId)
                    ->where('id', $storeId)->whereNull('delete_time')
                    ->field('id,user_id')->lock(true)->findOrEmpty();
                if ($store->isEmpty()) {
                    throw new DomainException('所属门店不存在或无权限');
                }
                if ($id !== null && self::staffQuery($id, $tenantId)->lock(true)->findOrEmpty()->isEmpty()) {
                    throw new DomainException('员工不存在或无权限');
                }

                $name = trim((string)$params['name']);
                if ($name === '') {
                    throw new DomainException('请填写员工姓名');
                }
                $mobile = trim((string)$params['mobile']);
                $duplicate = MerchantStaff::where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)->where('mobile', $mobile)->whereNull('delete_time');
                if ($id !== null) {
                    $duplicate->where('id', '<>', $id);
                }
                if (!$duplicate->field('id')->lock(true)->findOrEmpty()->isEmpty()) {
                    throw new DomainException(self::DUPLICATE_MOBILE);
                }

                $now = time();
                $fields = [
                    'store_id' => $storeId,
                    'user_id' => $store->user_id,
                    'name' => $name,
                    'mobile' => $mobile,
                    'update_time' => $now,
                ];
                foreach (['appid', 'openid'] as $field) {
                    if (array_key_exists($field, $params)) {
                        $value = trim((string)$params[$field]);
                        $fields[$field] = $value === '' ? null : $value;
                    }
                }
                if ($id === null) {
                    MerchantStaff::create($fields + ['tenant_id' => $tenantId, 'create_time' => $now]);
                } else {
                    self::staffQuery($id, $tenantId)->update($fields);
                }
            });
            return true;
        } catch (Throwable $e) {
            $fallback = str_contains($e->getMessage(), 'uk_store_mobile') ? self::DUPLICATE_MOBILE : '员工保存失败';
            self::setError($e instanceof DomainException ? $e->getMessage() : $fallback);
            return false;
        }
    }

    public static function delete(int $id, int $tenantId): bool
    {
        try {
            return Db::transaction(static function () use ($id, $tenantId): bool {
                $staff = self::staffQuery($id, $tenantId)->lock(true)->findOrEmpty();
                if ($staff->isEmpty()) {
                    self::setError('员工不存在或无权限');
                    return false;
                }
                if (!$staff->delete()) {
                    self::setError('员工删除失败');
                    return false;
                }
                return true;
            });
        } catch (Throwable $e) {
            self::setError('员工删除失败');
            return false;
        }
    }

    private static function staffQuery(int $id, int $tenantId)
    {
        return MerchantStaff::where('tenant_id', $tenantId)->where('id', $id)->whereNull('delete_time');
    }
}
