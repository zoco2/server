<?php

declare(strict_types=1);

namespace app\common\service\merchant;

use app\common\model\merchant\MerchantStore;
use app\common\model\tenant\Tenant;
use app\common\model\user\User;
use DomainException;
use think\db\exception\PDOException;
use think\facade\Db;

final class NfcCodeService
{
    private const TABLE = 'merchant_nfc_code';
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const MAX_BATCH_SIZE = 1000;
    private const QUERY_CHUNK_SIZE = 250;

    public static function generate(int $adminId, mixed $quantity): array
    {
        $count = self::positiveId($quantity, '请输入 1 至 1000 的整数');
        if ($count > self::MAX_BATCH_SIZE) {
            throw new DomainException('请输入 1 至 1000 的整数');
        }

        $batchNo = 'NFC' . date('YmdHis') . strtoupper(bin2hex(random_bytes(8)));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $codes = self::availableCodes($count);
            sort($codes, SORT_STRING);
            $now = time();
            $rows = array_map(static fn (string $code): array => [
                'code' => $code,
                'batch_no' => $batchNo,
                'created_admin_id' => $adminId,
                'create_time' => $now,
                'update_time' => $now,
            ], $codes);

            try {
                Db::transaction(static function () use ($rows): void {
                    foreach (array_chunk($rows, self::QUERY_CHUNK_SIZE) as $chunk) {
                        Db::name(self::TABLE)->insertAll($chunk);
                    }
                });
                return ['batch_no' => $batchNo, 'quantity' => $count];
            } catch (PDOException $error) {
                $driverCode = $error->getData()['PDO Error Info']['Driver Error Code'] ?? null;
                if (!in_array((int)$driverCode, [1062, 1213], true)) {
                    throw $error;
                }
            }
        }
        throw new DomainException('生成码牌失败，请重试');
    }

    private static function availableCodes(int $count): array
    {
        $codes = [];
        for ($round = 0; $round < 20 && count($codes) < $count; $round++) {
            $candidates = [];
            $needed = $count - count($codes);
            for ($i = 0; $i < $needed * 3 && count($candidates) < $needed; $i++) {
                $code = '';
                for ($position = 0; $position < 6; $position++) {
                    $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
                }
                if (self::validCode($code) && !isset($codes[$code])) {
                    $candidates[$code] = true;
                }
            }
            foreach (array_chunk(array_keys($candidates), self::QUERY_CHUNK_SIZE) as $chunk) {
                $existing = Db::name(self::TABLE)->whereIn('code', $chunk)->column('code');
                foreach (array_diff($chunk, $existing) as $code) {
                    $codes[$code] = true;
                }
            }
        }
        if (count($codes) !== $count) {
            throw new DomainException('生成码牌失败，请减少数量后重试');
        }
        return array_keys($codes);
    }

    public static function assignTenant(int $adminId, array $input): array
    {
        $ids = self::selectedIds($input['ids'] ?? null);
        $tenantId = self::positiveId($input['tenant_id'] ?? null, '请选择租户');

        return Db::transaction(static function () use ($ids, $tenantId, $adminId): array {
            if (!Tenant::where(['id' => $tenantId, 'disable' => 0])->lock(true)->find()) {
                throw new DomainException('租户不存在或已停用');
            }

            $now = time();
            $updated = Db::name(self::TABLE)->whereIn('id', $ids)->whereNull('tenant_id')->update([
                'tenant_id' => $tenantId,
                'tenant_assigned_admin_id' => $adminId,
                'tenant_assigned_at' => $now,
                'update_time' => $now,
            ]);
            if ($updated !== count($ids)) {
                throw new DomainException('部分码牌不存在或已分配，请刷新后重试');
            }
            return ['quantity' => $updated];
        });
    }

    public static function assignUser(int $tenantId, int $adminId, array $input): array
    {
        $ids = self::selectedIds($input['ids'] ?? null);
        $userId = self::positiveId($input['user_id'] ?? null, '请选择用户');

        return Db::transaction(static function () use ($ids, $tenantId, $userId, $adminId): array {
            if (!User::where(['tenant_id' => $tenantId, 'id' => $userId])->lock(true)->find()) {
                throw new DomainException('该用户不属于当前租户或已删除');
            }

            $now = time();
            $updated = Db::name(self::TABLE)->whereIn('id', $ids)->where('tenant_id', $tenantId)
                ->whereNull('user_id')->update([
                    'user_id' => $userId,
                    'user_assigned_admin_id' => $adminId,
                    'user_assigned_at' => $now,
                    'update_time' => $now,
                ]);
            if ($updated !== count($ids)) {
                throw new DomainException('部分码牌无权分配或已分配，请刷新后重试');
            }
            return ['quantity' => $updated];
        });
    }

    public static function activate(int $tenantId, int $userId, array $input): array
    {
        $storeId = self::positiveId($input['store_id'] ?? null, '请选择门店');
        $code = self::normalizeCode($input['code'] ?? null);
        $owner = ['tenant_id' => $tenantId, 'user_id' => $userId];

        return Db::transaction(static function () use ($owner, $storeId, $code): array {
            if (!MerchantStore::where($owner + ['id' => $storeId])->lock(true)->find()) {
                throw new DomainException('门店不存在或无权限');
            }

            $card = Db::name(self::TABLE)->where($owner + ['code' => $code])
                ->field('id,code,store_id,activated_at')->lock(true)->find();
            if (!$card) {
                throw new DomainException('码牌不存在或尚未分配给当前账号');
            }
            if ($card['store_id'] !== null) {
                if ((int)$card['store_id'] !== $storeId) {
                    throw new DomainException('该码牌已绑定其他门店');
                }
                return $card;
            }

            $now = time();
            Db::name(self::TABLE)->where('id', $card['id'])->update([
                'store_id' => $storeId,
                'activated_at' => $now,
                'update_time' => $now,
            ]);
            return array_replace($card, ['store_id' => $storeId, 'activated_at' => $now]);
        });
    }

    public static function storeLists(int $tenantId, int $userId, array $input): array
    {
        $storeId = self::positiveId($input['store_id'] ?? null, '请选择门店');
        $owner = ['tenant_id' => $tenantId, 'user_id' => $userId];
        if (!MerchantStore::where($owner + ['id' => $storeId])->find()) {
            throw new DomainException('门店不存在或无权限');
        }
        return self::paginate(
            Db::name(self::TABLE)->where($owner + ['store_id' => $storeId]),
            $input,
            'id,code,activated_at',
            ['activated_at' => 'desc', 'id' => 'desc']
        );
    }

    public static function adminLists(array $input, ?int $tenantId = null): array
    {
        $query = Db::name(self::TABLE);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        } elseif (isset($input['tenant_id']) && $input['tenant_id'] !== '') {
            $query->where('tenant_id', self::positiveId($input['tenant_id'], '租户 ID 无效'));
        }
        if (isset($input['user_id']) && $input['user_id'] !== '') {
            $query->where('user_id', self::positiveId($input['user_id'], '用户 ID 无效'));
        }
        $batch = is_string($input['batch_no'] ?? null) ? trim($input['batch_no']) : '';
        if ($batch !== '') {
            $query->where('batch_no', $batch);
        }
        switch ($input['status'] ?? '') {
            case 'platform_stock':
                $query->whereNull('tenant_id');
                break;
            case 'tenant_stock':
                $query->whereNotNull('tenant_id')->whereNull('user_id');
                break;
            case 'pending_activation':
                $query->whereNotNull('user_id')->whereNull('store_id');
                break;
            case 'active':
                $query->whereNotNull('store_id');
                break;
        }
        $result = self::paginate(
            $query,
            $input,
            'id,code,batch_no,tenant_id,user_id,store_id,create_time,tenant_assigned_at,user_assigned_at,activated_at'
        );
        foreach ($result['lists'] as &$row) {
            $row['status'] = match (true) {
                $row['tenant_id'] === null => 'platform_stock',
                $row['user_id'] === null => 'tenant_stock',
                $row['store_id'] === null => 'pending_activation',
                default => 'active',
            };
        }
        return $result;
    }

    public static function tenantChoices(string $keyword): array
    {
        $query = Tenant::where('disable', 0);
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        return $query->field('id,name')->order('id', 'desc')->limit(30)->select()->toArray();
    }

    public static function userChoices(int $tenantId, string $keyword): array
    {
        $query = User::where('tenant_id', $tenantId);
        if ($keyword !== '') {
            $query->whereLike('nickname|account|mobile', '%' . $keyword . '%');
        }
        return $query->field('id,nickname,account')->order('id', 'desc')->limit(30)->select()->toArray();
    }

    private static function paginate($query, array $input, string $fields, array $order = ['id' => 'desc']): array
    {
        $code = $input['code'] ?? '';
        if ($code !== '') {
            $query->where('code', self::normalizeCode($code));
        }
        $page = min(1000000, self::positiveId($input['page_no'] ?? 1, '页码无效'));
        $size = min(100, self::positiveId($input['page_size'] ?? 20, '每页数量无效'));
        return [
            'count' => (clone $query)->count(),
            'lists' => $query->field($fields)->order($order)->page($page, $size)->select()->toArray(),
            'page_no' => $page,
            'page_size' => $size,
        ];
    }

    private static function normalizeCode(mixed $value): string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';
        if (!self::validCode($code)) {
            throw new DomainException('请输入 6 位数字和字母组合的码牌编号');
        }
        return $code;
    }

    private static function validCode(string $code): bool
    {
        return preg_match('/\A(?=[A-Z0-9]*[0-9])(?=[A-Z0-9]*[A-Z])[A-Z0-9]{6}\z/', $code) === 1;
    }

    private static function selectedIds(mixed $values): array
    {
        if (!is_array($values) || $values === [] || count($values) > self::MAX_BATCH_SIZE) {
            throw new DomainException('请选择 1 至 1000 块码牌');
        }
        return array_values(array_unique(array_map(
            static fn ($id): int => self::positiveId($id, '码牌 ID 无效'),
            $values
        )));
    }

    private static function positiveId(mixed $value, string $message): int
    {
        $number = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($number === false) {
            throw new DomainException($message);
        }
        return $number;
    }
}
