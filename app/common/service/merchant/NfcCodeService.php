<?php

declare(strict_types=1);

namespace app\common\service\merchant;

use app\common\model\merchant\NfcCode;
use DomainException;
use think\db\exception\PDOException;

/**
 * 第一阶段的平台 NFC 库存服务。
 *
 * 编号生成沿用项目 SN 的“先查库判重”思路；批内集合去重减少无效写入，
 * 唯一索引处理查库后到写入前的并发竞争。所有库存操作通过 NfcCode 模型完成。
 */
final class NfcCodeService
{
    /** 统一使用大写，避免人工录入时大小写混淆；每个编号还必须同时含数字和字母。 */
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** 单次请求的业务上限，限制随机生成、查库和写入的工作量。 */
    private const MAX_BATCH_SIZE = 1000;

    /** 查询与插入每次最多处理 250 条，避免构造过大的 IN 条件或插入语句。 */
    private const QUERY_CHUNK_SIZE = 250;

    /**
     * 为一次平台操作生成一批码牌，任一写入分块失败都会回滚整批。
     *
     * @param int $adminId 当前登录的平台管理员 ID，由控制器提供
     * @param mixed $quantity 生成数量，允许整数或表单中的整数字符串，范围 1 至 1000
     * @return array{batch_no: string, quantity: int} 已成功落库的批次和数量
     * @throws DomainException 参数无效，或有限次数内仍无法生成成功
     * @throws PDOException 非唯一键冲突、非死锁的数据库异常，交由上层统一处理
     */
    public static function generate(int $adminId, mixed $quantity): array
    {
        // 写库前完成参数校验，避免非法请求创建空批次或留下部分库存。
        self::positiveId($adminId, '缺少平台管理员');
        $count = self::positiveId($quantity, '请输入 1 至 1000 的整数');
        if ($count > self::MAX_BATCH_SIZE) {
            throw new DomainException('请输入 1 至 1000 的整数');
        }

        // 批次号由 NFC + 当前日期时间 + 16 位随机十六进制字符组成，总长 33 位。
        // 同一次调用的重试继续使用此批次号，前端成功后可据此查询本次生成结果。
        $batchNo = 'NFC' . date('YmdHis') . strtoupper(bin2hex(random_bytes(8)));
        // 事务必须使用模型实际连接，保证后续模型写入参与同一个事务。
        $connection = NfcCode::query()->getConnection();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // 重试时重新查库和补码，避开其他请求刚刚占用的编号。
            $codes = self::availableCodes($count);
            // 多个请求按相同编号顺序写入，有助于降低交叉获取唯一索引锁时的死锁概率。
            sort($codes, SORT_STRING);
            // 同批记录共享管理员和时间信息，方便追溯生成来源。
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
                        // 分块降低单条 SQL 的体积；strict 防止表结构不匹配时静默忽略字段。
                        // 所有分块都在外层事务内，只有全部插入完成才算生成成功。
                        NfcCode::query()->strict(true)->insertAll($chunk);
                    }
                });
                return ['batch_no' => $batchNo, 'quantity' => $count];
            } catch (PDOException $error) {
                // 查库与插入之间存在并发窗口：1062 表示唯一键冲突，1213 表示死锁。
                // 事务已回滚，仅对这两类可重试异常重新生成，最多尝试五次。
                $driverCode = $error->getData()['PDO Error Info']['Driver Error Code'] ?? null;
                if (!in_array((int)$driverCode, [1062, 1213], true)) {
                    throw $error;
                }
            }
        }
        throw new DomainException('生成码牌失败，请重试');
    }

    /**
     * 收集数量足够且查库时未被占用的候选码，不在此方法中写入数据库。
     *
     * @param int $count 已由 generate 校验的目标数量
     * @return list<string> 批内唯一、符合格式且查库时不存在的编号
     * @throws DomainException 二十轮补码后仍未达到目标数量，防止极端情况下无限循环
     */
    private static function availableCodes(int $count): array
    {
        // 使用编号作为数组键，保存前面轮次中已通过数据库判重的结果。
        $codes = [];
        for ($round = 0; $round < 20 && count($codes) < $count; $round++) {
            $candidates = [];
            $needed = $count - count($codes);
            // 本轮最多尝试缺口数量的三倍，允许丢弃格式不符或重复的随机结果。
            for ($i = 0; $i < $needed * 3 && count($candidates) < $needed; $i++) {
                $code = '';
                for ($position = 0; $position < 6; $position++) {
                    $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
                }
                // 同时排除纯数字、纯字母和前面轮次的编号；候选集合键也会合并本轮重复值。
                if (self::validCode($code) && !isset($codes[$code])) {
                    $candidates[$code] = true;
                }
            }
            foreach (array_chunk(array_keys($candidates), self::QUERY_CHUNK_SIZE) as $chunk) {
                // 按批查询已存在的 code，取差集加入结果，下一轮只补剩余缺口。
                // 此处只是提前过滤，最终唯一性仍由数据库唯一索引保证。
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

    /**
     * 平台库存分页，编号和批次都采用精确匹配，未填写的条件不参与查询。
     *
     * @param array $input 请求查询参数：code、batch_no、page_no、page_size
     * @return array{count: int, lists: array, page_no: int, page_size: int}
     * @throws DomainException 编号格式、页码或每页数量无效
     */
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
        // 限制分页边界，单页最多 100 条，默认从第一页读取 20 条。
        $page = min(1000000, self::positiveId($input['page_no'] ?? 1, '页码无效'));
        $size = min(100, self::positiveId($input['page_size'] ?? 20, '每页数量无效'));
        return [
            // 克隆查询分别执行总数和分页，保证统计不带分页限制并复用同一组筛选条件。
            'count' => (clone $query)->count(),
            // 主键倒序展示最新生成记录，只返回第一阶段的库存字段。
            'lists' => $query->field('id,code,batch_no,created_admin_id,create_time')
                ->order('id', 'desc')->page($page, $size)->select()->toArray(),
            'page_no' => $page,
            'page_size' => $size,
        ];
    }

    /**
     * 去掉输入两端空白并转为大写，使手工输入的编号与库存采用相同格式。
     *
     * @param mixed $value 请求中的编号，必须是字符串
     * @return string 格式有效的六位编号
     * @throws DomainException 空值、非字符串或不满足数字字母混合规则
     */
    private static function normalizeCode(mixed $value): string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';
        if (!self::validCode($code)) {
            throw new DomainException('请输入 6 位数字和字母组合的码牌编号');
        }
        return $code;
    }

    /**
     * 两个前瞻分别要求至少一位数字和一位字母，主体限定六位大写字母或数字。
     * \A 和 \z 要求匹配完整字符串，避免尾部换行等额外字符被接受。
     *
     * @param string $code 待检测的编号，不在此方法中改写输入
     * @return bool 是否为完整的六位数字字母混合编号
     */
    private static function validCode(string $code): bool
    {
        return preg_match('/\A(?=[A-Z0-9]*[0-9])(?=[A-Z0-9]*[A-Z])[A-Z0-9]{6}\z/', $code) === 1;
    }

    /**
     * 校验正整数，供管理员 ID、生成数量和分页参数复用。
     *
     * 允许表单传来的整数字符串，拒绝浮点数、布尔值、数组及小于 1 的数值，
     * 避免直接强制转 int 时把非法输入截断成看似有效的数量。
     *
     * @param mixed $value 待校验的原始输入
     * @param string $message 校验失败时面向调用方的提示
     * @return int 校验后的正整数
     * @throws DomainException 输入不是有效正整数
     */
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
