<?php

declare(strict_types=1);

namespace app\common\service\merchant;

use app\common\model\merchant\NfcCode;
use DomainException;
use think\db\exception\PDOException;

final class NfcCodeService
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const MAX_BATCH_SIZE = 1000;
    private const QUERY_CHUNK_SIZE = 250;

    /** 查库排除已有编号，再通过唯一索引兜底并发冲突，整批成功后返回。 */
    public static function generate(int $adminId, mixed $quantity): array
    {
        self::positiveId($adminId, '缺少平台管理员');
        $count = self::positiveId($quantity, '请输入 1 至 1000 的整数');
        if ($count > self::MAX_BATCH_SIZE) {
            throw new DomainException('请输入 1 至 1000 的整数');
        }

        $batchNo = 'NFC' . date('YmdHis') . strtoupper(bin2hex(random_bytes(8)));
        $connection = NfcCode::query()->getConnection();
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
                $connection->transaction(static function () use ($rows): void {
                    foreach (array_chunk($rows, self::QUERY_CHUNK_SIZE) as $chunk) {
                        NfcCode::query()->strict(true)->insertAll($chunk);
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
                $existing = NfcCode::whereIn('code', $chunk)->column('code');
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

    public static function adminLists(array $input): array
    {
        $query = NfcCode::query();
        $code = $input['code'] ?? '';
        if ($code !== '') {
            $query->where('code', self::normalizeCode($code));
        }
        $batch = is_string($input['batch_no'] ?? null) ? trim($input['batch_no']) : '';
        if ($batch !== '') {
            $query->where('batch_no', $batch);
        }
        $page = min(1000000, self::positiveId($input['page_no'] ?? 1, '页码无效'));
        $size = min(100, self::positiveId($input['page_size'] ?? 20, '每页数量无效'));
        return [
            'count' => (clone $query)->count(),
            'lists' => $query->field('id,code,batch_no,created_admin_id,create_time')
                ->order('id', 'desc')->page($page, $size)->select()->toArray(),
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
