<?php

declare(strict_types=1);

use think\db\ConnectionInterface;
use think\facade\Db;

/** 预检：php scripts/migrate-publish-task-shards.php。停用旧任务写入和消费后，加 --apply 执行。 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$arguments = array_slice($argv, 1);
if ($arguments !== [] && $arguments !== ['--apply']) {
    fwrite(STDERR, "用法：php scripts/migrate-publish-task-shards.php [--apply]\n");
    exit(2);
}
$apply = $arguments === ['--apply'];

require dirname(__DIR__) . '/runtime-loader.php';
(new \AigcRuntimeApp(dirname(__DIR__)))->initialize();

try {
    $db = Db::connect();
    $prefix = (string)$db->getConfig('prefix');
    $sourceName = $prefix . 'publish_task';
    $source = quotePublishTaskIdentifier($sourceName);
    $tenantTable = quotePublishTaskIdentifier($prefix . 'tenant');
    $tenants = $db->query("SELECT id, sn FROM {$tenantTable} WHERE tactics = 1 AND delete_time IS NULL ORDER BY id");
    if ($tenants === []) {
        echo "没有需要迁移的分表租户。\n";
        exit(0);
    }

    $columns = [
        'id', 'tenant_id', 'user_id', 'staff_id', 'content', 'platforms', 'notice_type', 'push_mode',
        'schedule_type', 'push_time', 'weekday', 'start_date', 'end_date', 'status', 'next_wake_at',
        'last_run_at', 'last_run_status', 'last_error', 'create_time', 'update_time',
    ];
    $identityColumns = [
        'user_id', 'staff_id', 'content', 'platforms', 'notice_type', 'push_mode', 'schedule_type',
        'push_time', 'weekday', 'start_date', 'end_date', 'create_time',
    ];
    $differentTask = implode(' OR ', array_map(static function (string $column): string {
        $field = quotePublishTaskIdentifier($column);
        return "NOT (src.{$field} <=> dst.{$field})";
    }, $identityColumns));
    requirePublishTaskColumns($db, $source, $columns);
    $tableNames = array_column(
        $db->query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()'),
        'TABLE_NAME'
    );

    $plans = [];
    foreach ($tenants as $tenant) {
        $tenantId = (int)$tenant['id'];
        $tenantSn = (string)$tenant['sn'];
        $shardName = $sourceName . '_' . $tenantSn;
        $shard = quotePublishTaskIdentifier($shardName);
        if ($tenantId <= 0 || $tenantSn === '' || isset($plans[strtolower($shardName)])) {
            throw new RuntimeException('分表租户标识冲突：' . $shardName);
        }

        $exists = in_array($shardName, $tableNames, true);
        if ($exists) {
            requirePublishTaskColumns($db, $shard, $columns);
            // 已迁移任务可以推进调度状态，同一 ID 的任务归属与创建时间必须一致。
            $conflicts = $db->query(
                "SELECT dst.id FROM {$shard} AS dst WHERE NOT (dst.tenant_id <=> ?) LIMIT 1",
                [$tenantId]
            );
            if (!$conflicts) {
                $conflicts = $db->query(
                    "SELECT dst.id FROM {$shard} AS dst INNER JOIN {$source} AS src ON src.id = dst.id
                     WHERE src.tenant_id = ? AND ({$differentTask}) LIMIT 1",
                    [$tenantId]
                );
            }
            if ($conflicts) {
                throw new RuntimeException("任务记录冲突：{$shardName} ID " . $conflicts[0]['id']);
            }
        }
        $plans[strtolower($shardName)] = [$tenantId, $shard, $exists];
    }

    $targetFields = implode(', ', array_map('quotePublishTaskIdentifier', $columns));
    $sourceFields = implode(', ', array_map(static fn (string $column) => 'src.' . quotePublishTaskIdentifier($column), $columns));
    foreach ($plans as [$tenantId, $shard, $exists]) {
        if (!$apply) {
            $count = $exists
                ? $db->query("SELECT COUNT(*) AS total FROM {$source} AS src LEFT JOIN {$shard} AS dst
                    ON dst.id = src.id WHERE src.tenant_id = ? AND dst.id IS NULL", [$tenantId])
                : $db->query("SELECT COUNT(*) AS total FROM {$source} WHERE tenant_id = ?", [$tenantId]);
            printf("租户 %d：%s，待复制 %d 条任务\n", $tenantId, $shard, (int)$count[0]['total']);
            continue;
        }

        $db->execute("CREATE TABLE IF NOT EXISTS {$shard} LIKE {$source}");
        $copied = $db->execute(
            "INSERT INTO {$shard} ({$targetFields}) SELECT {$sourceFields} FROM {$source} AS src
             LEFT JOIN {$shard} AS dst ON dst.id = src.id WHERE src.tenant_id = ? AND dst.id IS NULL",
            [$tenantId]
        );
        printf("租户 %d：%s，已复制 %d 条任务\n", $tenantId, $shard, $copied);
    }

    echo $apply ? "迁移完成，原表数据保留。\n" : "预检通过，停止旧版本写入和 MNS 消费后可执行 --apply。\n";
} catch (Throwable $error) {
    fwrite(STDERR, '发布任务分表迁移失败：' . $error->getMessage() . PHP_EOL);
    exit(1);
}

/** 仅允许预期的 MySQL 标识符，便于安全拼接 SQL。 */
function quotePublishTaskIdentifier(string $name): string
{
    if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name)) {
        throw new RuntimeException('发布任务迁移遇到无效 SQL 标识符');
    }
    return chr(96) . $name . chr(96);
}

/** 检查源表和已有分表，避免旧版本表结构导致数据遗漏。 */
function requirePublishTaskColumns(ConnectionInterface $db, string $table, array $columns): void
{
    $fields = array_column($db->query("SHOW COLUMNS FROM {$table}"), 'Field');
    $missing = array_diff($columns, $fields);
    if ($missing !== []) {
        throw new RuntimeException("{$table} 缺少字段：" . implode(', ', $missing));
    }
}
