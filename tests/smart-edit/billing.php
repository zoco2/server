<?php

declare(strict_types=1);

/** php tests/smart-edit/billing.php (pdo_sqlite and mbstring) */
namespace app\common\service\mq {
    class LightMqService
    {
        public static array $messages = [];
        public function dispatchJob($queue, $job, $delay): void { self::$messages[] = $job; }
    }
}

namespace app\common\service\smart_edit {
    class SmartEditImsConfigService
    {
        public static function require(int $tenant): array { return ['region' => 'fixture', 'domain' => 'example.test']; }
    }
    class SmartEditMaterialService
    {
        public static function outputTarget($config, $no): array { return ['uri' => $no, 'url' => $no]; }
        public static function mediaUrl($config, $tenant, $uri): string { return $uri; }
    }
}

namespace app\common\service\point_alert {
    class AlertService { public static function touch(int $tenant, int $user): void {} }
}

namespace think\facade {
    class Log
    {
        public static function channel($name): self { return new self(); }
        public function info($message): void {}
        public function error($message): void {}
    }
}

namespace {
    use app\common\enum\AdminTerminalEnum;
    use app\common\job\SmartEditGroupExpandJob;
    use app\common\model\smart_edit\SmartEditTask as Task;
    use app\common\service\mq\LightMqService as Queue;
    use app\common\service\smart_edit\SmartEditBillingService as Billing;
    use app\common\service\smart_edit\SmartEditGroupService as Groups;
    use app\common\service\smart_edit\SmartEditPollService as Poll;
    use app\common\service\smart_edit\SmartEditService as Tasks;
    use think\App;
    use think\facade\Config;
    use think\facade\Db;
    use think\Model;

    $root = dirname(__DIR__, 2);
    require $root . '/vendor/autoload.php';
    require $root . '/vendor/topthink/framework/src/helper.php';
    require_once $root . '/app/common.php';

    function check(bool $ok, string $message): void
    {
        if (!$ok) throw new RuntimeException($message);
    }
    function balance(int $expected): void
    {
        $actual = (float)Db::name('user')->where('id', 8)->value('user_money');
        check($actual === (float)$expected, "Expected balance $expected, got $actual");
    }

    $app = new App($root);
    Config::set(['default' => 'test', 'connections' => ['test' => [
        'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true, 'trigger_sql' => false,
    ]]], 'database');
    Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File']]], 'cache');
    Model::setDb($app->make('db'));
    Model::maker(static fn(Model $model) => $model->setDateFormat('Y-m-d H:i:s'));
    $schema = file_get_contents($root . '/public/install/db/like.sql');
    foreach (['smart_edit_task', 'smart_edit_task_item', 'smart_edit_group', 'user', 'user_account_log'] as $table) {
        $pattern = '/CREATE TABLE (?:IF NOT EXISTS )?`la_' . $table . '`\s*\((.*?)\)\s*ENGINE/is';
        check((bool)preg_match($pattern, $schema, $match), "Missing installation table $table");
        preg_match_all('/^\s*`(\w+)`\s+(\w+)/m', $match[1], $columns, PREG_SET_ORDER);
        $fields = [];
        foreach ($columns as [, $name, $type]) {
            $sqliteType = str_contains($type, 'int') ? 'INTEGER' : 'TEXT';
            $fields[] = '"' . $name . '" ' . ($name === 'id' ? 'INTEGER PRIMARY KEY' : $sqliteType);
        }
        Db::execute("CREATE TABLE la_$table (" . implode(',', $fields) . ')');
    }
    Db::execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, tactics INTEGER, sn TEXT, delete_time INTEGER)');
    Db::execute('CREATE TABLE la_tenant_config (id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT)');
    Db::name('tenant')->insert(['id' => 3, 'tactics' => 0, 'sn' => 'test']);
    Db::name('user')->insert(['id' => 8, 'tenant_id' => 3, 'user_money' => 100]);
    Db::name('tenant_config')->insert(['tenant_id' => 3, 'type' => 'business_price', 'name' => 'smart_edit_points', 'value' => 7]);
    request()->tenantId = 3;
    request()->source = AdminTerminalEnum::USER;

    // A delayed notification from the original attempt must never refund an admin retry.
    $clips = [['uri' => 'a.mp4', 'out' => 2], ['uri' => 'b.mp4', 'out' => 3]];
    $input = ['clips' => $clips, 'expected_unit_points' => 7];
    $single = Tasks::generate(3, 8, $input);
    balance(93);
    $old = Task::findOrFail($single['id']);
    Billing::fail($old, ['status' => Task::STATUS_FAILED]);
    Billing::fail($old, ['status' => Task::STATUS_FAILED]);
    balance(100);
    Db::name('tenant_config')->where('name', 'smart_edit_points')->update(['value' => 5]);
    try {
        Tasks::generate(3, 8, $input);
        throw new LogicException('Accepted a stale unit price');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), '单价已更新'), $e->getMessage());
    }
    Tasks::retry(Task::findOrFail($single['id']));
    Billing::fail($old, ['status' => Task::STATUS_FAILED]);
    balance(93);
    Tasks::cancel(3, 8, $single['id']);
    Tasks::delete(3, 8, $single['id']);
    balance(100);

    // A 2 x 3 group takes its original five-point quote into the expansion worker.
    $group = Groups::create(3, 8, ['expected_unit_points' => 5, 'shots' => [
        ['videos' => $clips], ['videos' => [...$clips, ['uri' => 'c.mp4', 'out' => 4]]],
    ]]);
    $job = new SmartEditGroupExpandJob(['batch_no' => $group['batch_no']]);
    Db::name('tenant_config')->where('name', 'smart_edit_points')->update(['value' => 9]);
    Db::name('user')->where('id', 8)->update(['user_money' => 29]);
    $job->handle();
    balance(29);
    check(Task::where('batch_no', $group['batch_no'])->count() === 0, 'Underfunded group left partial tasks');
    Db::name('user')->where('id', 8)->update(['user_money' => 100]);
    $job->handle();
    $job->handle();
    balance(70);
    $ids = Task::where('batch_no', $group['batch_no'])->order('id')->column('id');
    check(count($ids) === 6, 'Duplicate expansion or missing combinations');

    // Simulate a crash after committing the group but before handing it to the queue.
    Task::whereIn('id', $ids)->update(['next_poll_time' => 0, 'update_time' => time() - 1200]);
    Queue::$messages = [];
    check(Poll::run(6) === 6 && Poll::run(6) === 0, 'Stranded tasks were not recovered exactly once');
    check(count(Queue::$messages) === 6, 'Expected a single recovery dispatch per task');
    balance(70);
    Tasks::cancel(3, 8, $ids[0]);
    balance(75);
    Task::where('id', $ids[1])->update(['status' => Task::STATUS_SUCCESS]);
    Groups::delete(3, 8, $group['batch_no']);
    balance(95);
    check(Db::name('user_account_log')->count() === 10, 'Unexpected number of charges or refunds');
    echo "PASS smart-edit price snapshot, retry isolation, batch rollback, recovery and partial refund\n";
}
