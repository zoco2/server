<?php

namespace app\common\service\wenan;

use app\common\service\brand\BrandCatalogService;
use InvalidArgumentException;
use think\facade\Db;

final class SeedCardJobService
{
    private const TABLE = 'wenan_seed_card_job';

    private static function scope(int $tenant, int $user, int $store, string $mode): array
    {
        return ['tenant_id' => $tenant, 'user_id' => $user, 'store_id' => $store, 'mode' => $mode];
    }

    public static function submit(int $tenant, int $user, array $input): array
    {
        $storeId = (int)($input['store_id'] ?? 0);
        $mode = (string)($input['mode'] ?? '');
        if (!in_array($mode, ['copy', 'topic'], true)) {
            throw new InvalidArgumentException('生成类型无效');
        }
        $outputGroupId = filter_var($input['output_group_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($outputGroupId === false) {
            throw new InvalidArgumentException($mode === 'copy' ? '请选择文案组' : '请选择标签组');
        }
        $count = filter_var($input['count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($count === false || $prompt === '' || mb_strlen($prompt) > 300) {
            throw new InvalidArgumentException('请输入 1–300 字提示词和 1–100 的整数数量');
        }

        return Db::transaction(static function () use ($tenant, $user, $storeId, $mode, $outputGroupId, $count, $prompt) {
            $store = Db::name('merchant_store')->where([
                'tenant_id' => $tenant, 'user_id' => $user, 'id' => $storeId,
            ])->whereNull('delete_time')->lock(true)->find();
            if (!$store) throw new InvalidArgumentException('门店不存在或无权限');

            $latest = self::latest($tenant, $user, $storeId, $mode);
            if ($latest && in_array($latest['status'], ['pending', 'running'], true)) return $latest;

            self::assertOutputGroup($tenant, $user, $mode, $outputGroupId);
            $address = implode('', array_map(static fn ($key) => $store[$key] ?? '', ['province', 'city', 'district', 'address']));
            $context = "门店：{$store['name']}\n门店简介：" . mb_substr((string)$store['intro'], 0, 500)
                . "\n门店地址：" . mb_substr($address, 0, 300) . "\n用户提示词：{$prompt}";
            $row = self::scope($tenant, $user, $storeId, $mode) + [
                'output_group_id' => $outputGroupId, 'quantity' => $count, 'context' => $context,
                'items' => '[]', 'error' => '', 'status' => 'pending',
            ];
            $row['id'] = Db::name(self::TABLE)->insertGetId($row);
            return self::present($row);
        });
    }

    public static function latest(int $tenant, int $user, int $store, string $mode): ?array
    {
        $row = Db::name(self::TABLE)->where(self::scope($tenant, $user, $store, $mode))->order('id', 'desc')->find();
        return $row ? self::present($row) : null;
    }

    private static function present(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'output_group_id' => isset($row['output_group_id']) ? (int)$row['output_group_id'] : null,
            'quantity' => (int)$row['quantity'], 'status' => $row['status'],
            'items' => self::libraryItems($row), 'error' => $row['error'],
        ];
    }

    public static function work(): bool
    {
        $now = time();
        Db::name(self::TABLE)->where('status', 'running')->where('lease_until', '<=', $now)
            ->update(['status' => 'failed', 'error' => '任务执行中断，已保留生成结果']);
        $job = Db::name(self::TABLE)->where('status', 'pending')->order('id')->find();
        if (!$job) return false;

        $lease = $now + 300;
        $query = static fn () => Db::name(self::TABLE)->where('id', $job['id']);
        if (!$query()->where('status', 'pending')->where('processed', $job['processed'])->update(['status' => 'running', 'lease_until' => $lease])) {
            return false;
        }

        try {
            $items = self::libraryItems($job);
            $amount = min(5, $job['quantity'] - $job['processed']);
            $topic = $job['mode'] === 'topic';
            $hint = $topic
                ? "生成 {$amount} 条适用于视频发布的不同 #话题，每条 2–15 字。"
                : "生成 {$amount} 条可直接用于视频发布的不同标题，每条 15–50 字，不含话题标签。";
            $response = WenanEnhanceService::write((int)$job['tenant_id'], (int)$job['user_id'], [
                'scene' => 'general', 'max_length' => $amount * ($topic ? 24 : 80),
                'text' => $job['context'] . "\n避免重复：" . implode('、', array_slice($items, -20)),
                'hint' => $hint . '每行一条，不加编号、引号或解释。只使用提供的事实。',
            ]);
            $next = array_slice(array_values(array_diff(self::parse($response['text'], $topic), $items)), 0, $amount);
            if (!$next) throw new InvalidArgumentException('本批未返回有效的新内容，请调整提示词后重试');
            $processed = $job['processed'] + $amount;
            $items = array_merge($items, $next);
            $done = $processed >= $job['quantity'];
            $update = [
                'processed' => $processed,
                'status' => $done ? 'success' : 'pending',
                'error' => $done && count($items) < $job['quantity'] ? '模型返回的不同内容少于目标数量，已保留结果' : '',
            ];
            Db::transaction(static function () use ($query, $job, $lease, $topic, $next, $update) {
                $owned = $query()->where('status', 'running')->where('lease_until', $lease);
                if (!$owned->lock(true)->find()) return;

                $library = self::libraryQuery($job);
                $owner = ['tenant_id' => $job['tenant_id'], 'user_id' => $job['user_id']];
                $outputGroupId = (int)($job['output_group_id'] ?? 0);
                self::assertOutputGroup((int)$job['tenant_id'], (int)$job['user_id'], $job['mode'], $outputGroupId, true);
                $now = time();
                $ids = json_decode($job['items'], true, 512, JSON_THROW_ON_ERROR);
                foreach ($next as $item) {
                    $entry = $owner + [
                        'name' => mb_substr($topic ? ltrim($item, '#') : $item, 0, 100),
                        'create_time' => $now,
                        'update_time' => $now,
                    ];
                    if (!$topic) $entry['content'] = $item;
                    $id = (string)(clone $library)->insertGetId($entry);
                    $ids[] = $id;
                    if ($outputGroupId > 0) {
                        Db::name('work_item')->insert($owner + [
                            'type' => $job['mode'], 'group_id' => $outputGroupId,
                            'source_ref' => 'merchant_' . $job['mode'] . ':' . $id, 'title' => $entry['name'],
                            'create_time' => $now, 'update_time' => $now,
                        ]);
                    }
                }
                $query()->update($update + [
                    'items' => json_encode($ids, JSON_THROW_ON_ERROR), 'lease_until' => 0,
                ]);
            });
        } catch (\Throwable $e) {
            $update = ['status' => 'failed', 'error' => $e instanceof InvalidArgumentException
                ? $e->getMessage() : '生成失败，已保留生成结果，请稍后重试'];
            $query()->where('status', 'running')->where('lease_until', $lease)->update($update + ['lease_until' => 0]);
        }
        return true;
    }

    private static function assertOutputGroup(int $tenant, int $user, string $mode, int $groupId, bool $lock = false): void
    {
        if ($groupId === 0) return;
        $group = Db::name('work_group')->where([
            'id' => $groupId, 'tenant_id' => $tenant, 'user_id' => $user, 'type' => $mode,
        ])->whereNull('delete_time')->lock($lock)->find();
        if (!$group) throw new InvalidArgumentException('所选作品组不存在或不可用');
    }

    private static function libraryQuery(array $job)
    {
        $base = $job['mode'] === 'topic' ? 'merchant_topic' : 'merchant_copy';
        $name = BrandCatalogService::tenantTable($base, (int)$job['tenant_id']);
        $query = Db::name($name);
        if ($name !== $base && !$query->getConnection()->getTableInfo($query->getTable())) {
            return Db::name($base);
        }
        return $query;
    }

    private static function libraryItems(array $job): array
    {
        $ids = json_decode($job['items'], true, 512, JSON_THROW_ON_ERROR);
        $ids = array_filter($ids, static fn ($id) => is_string($id) && ctype_digit($id));
        if (!$ids) return [];
        $topic = $job['mode'] === 'topic';
        $rows = self::libraryQuery($job)->where([
            'tenant_id' => $job['tenant_id'], 'user_id' => $job['user_id'],
        ])->whereIn('id', $ids)->column($topic ? 'name' : 'content', 'id');
        $items = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) $items[] = ($topic ? '#' : '') . $rows[$id];
        }
        return $items;
    }

    private static function parse(string $text, bool $topic): array
    {
        $clean = trim(preg_replace('/^\x60\x60\x60[^\n]*\n?|\x60\x60\x60$/m', '', $text));
        $decoded = json_decode($clean, true);
        $items = [];
        foreach (is_array($decoded) && array_is_list($decoded) ? $decoded : preg_split('/\R/u', $clean) as $line) {
            if (!is_string($line)) continue;
            $line = trim(preg_replace('/^(?:[-*•]\s+|\d+[.、)）]\s*)/u', '', trim($line)), " \t\r\n\"");
            if ($line === '' || $line === '#' || $line === '＃' || preg_match('/^(?:以下|生成结果|标题文案|话题列表)[：:]/u', $line)) continue;
            if ($topic) {
                preg_match_all('/#[^#\s，,。；;！!？?、：:]+/u', str_replace('＃', '#', $line), $matches);
                array_push($items, ...($matches[0] ?: ['#' . preg_replace('/\s+/u', '', $line)]));
            } else {
                $items[] = $line;
            }
        }
        return array_unique($items);
    }
}
