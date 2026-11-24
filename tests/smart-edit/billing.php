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
        public static function joinUrl(string $domain, string $uri): string { return $domain . '/' . $uri; }
        public static function requirePlatform(): array { return ['region' => 'fixture', 'domain' => 'example.test', 'access_key' => 'fixture', 'secret_key' => 'fixture']; }
    }
    class ImsClient
    {
        public function getJob(string $id): array { return ['status' => 'success', 'duration' => 3.0]; }
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
    use app\common\model\smart_edit\SmartEditGroup as Group;
    use app\common\model\smart_edit\SmartEditTask as Task;
    use app\common\service\smart_edit\SmartEditBillingService as Billing;
    use app\common\service\smart_edit\SmartEditGroupService as Groups;
    use app\common\service\smart_edit\SmartEditService as Tasks;
    use app\common\service\WorkspaceSmartEditAssetService as Library;
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
    foreach (['smart_edit_task', 'smart_edit_task_item', 'smart_edit_group', 'tenant_file', 'user', 'user_account_log', 'work_group', 'work_item', 'user_asset_state'] as $table) {
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
    Db::execute('CREATE UNIQUE INDEX uk_owner_source ON la_work_item(tenant_id,user_id,source_ref)');
    Db::connect()->getPdo()->sqliteCreateFunction('CONCAT', static fn (...$args) => implode('', $args));
    Db::execute("INSERT INTO la_work_group (id, tenant_id, user_id, type, delete_time) VALUES
        (10, 3, 8, 'video', NULL), (11, 3, 9, 'video', NULL), (12, 4, 8, 'video', NULL),
        (13, 3, 8, 'copy', NULL), (14, 3, 8, 'video', 500)");
    Db::execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, tactics INTEGER, sn TEXT, delete_time INTEGER)');
    Db::execute('CREATE TABLE la_tenant_config (id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT)');
    Db::execute('CREATE TABLE la_config (id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT)');
    Db::name('tenant')->insert(['id' => 3, 'tactics' => 0, 'sn' => 'test']);
    Db::name('user')->insert(['id' => 8, 'tenant_id' => 3, 'user_money' => 100]);
    Db::name('config')->insert(['type' => 'business_price', 'name' => 'settings', 'value' => '{"smart_edit_points":7}']);
    request()->tenantId = 3;
    request()->source = AdminTerminalEnum::USER;

    // A delayed notification from the original attempt must never refund an admin retry.
    $clips = [['uri' => 'a.mp4', 'out' => 2], ['uri' => 'b.mp4', 'out' => 3]];
    $input = ['clips' => $clips, 'expected_unit_points' => 7];
    foreach ([11, 12, 13, 14, 999, -1, '9e2', true, [], null, '999999999999999999999'] as $invalid) {
        try {
            Tasks::generate(3, 8, $input + ['work_group_id' => $invalid]);
            throw new LogicException('Invalid work group accepted');
        } catch (InvalidArgumentException $expected) {
            check(str_contains($expected->getMessage(), '分组'), $expected->getMessage());
        }
    }
    check(Task::count() === 0 && Db::name('work_item')->count() === 0, 'Invalid group created task or membership');
    balance(100);
    Db::name('user')->where('id', 8)->update(['user_money' => 6]);
    try {
        Tasks::generate(3, 8, $input + ['work_group_id' => 10]);
        throw new LogicException('Underfunded task created');
    } catch (RuntimeException $expected) {
        check(str_contains($expected->getMessage(), '不足'), $expected->getMessage());
    }
    check(Task::count() === 0 && Db::name('work_item')->count() === 0, 'Reservation failure left a task or membership');
    Db::name('user')->where('id', 8)->update(['user_money' => 100]);
    $single = Tasks::generate(3, 8, $input + ['work_group_id' => '10']);
    $singleRef = 'smart_edit:task:' . $single['id'];
    check((int)Db::name('work_item')->where('source_ref', $singleRef)->value('group_id') === 10, 'Missing single membership');
    balance(93);
    $old = Task::findOrFail($single['id']);
    Billing::fail($old, ['status' => Task::STATUS_FAILED]);
    Billing::fail($old, ['status' => Task::STATUS_FAILED]);
    balance(100);
    Db::name('config')->where('name', 'settings')->update(['value' => '{"smart_edit_points":5}']);
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

    check(Db::name('work_item')->where('source_ref', $singleRef)->count() === 1, 'Retry duplicated membership');

    // A 2 x 3 group takes its original five-point quote into the expansion worker.
    $groupInput = ['expected_unit_points' => 5, 'work_group_id' => 10, 'shots' => [
        ['videos' => $clips], ['videos' => [...$clips, ['uri' => 'c.mp4', 'out' => 4]]],
    ]];
    try {
        Groups::create(3, 8, array_replace($groupInput, ['work_group_id' => 11]));
        throw new LogicException('Accepted another user\'s group for batch');
    } catch (InvalidArgumentException $expected) {
        check(Group::count() === 0, 'Unauthorized creation reserved a batch');
        balance(100);
    }
    Db::name('user')->where('id', 8)->update(['user_money' => 29]);
    try {
        Groups::create(3, 8, $groupInput);
        throw new LogicException('Underfunded group started');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), '不足'), $e->getMessage());
    }
    check(Group::count() === 0, 'Insufficient balance created a group');
    balance(29);
    Db::name('user')->where('id', 8)->update(['user_money' => 100]);
    $group = Groups::create(3, 8, $groupInput);
    balance(70);
    $job = new SmartEditGroupExpandJob(['batch_no' => $group['batch_no']]);
    Db::name('config')->where('name', 'settings')->update(['value' => '{"smart_edit_points":9}']);
    $job->handle();
    $job->handle();
    balance(70);
    $ids = Task::where('batch_no', $group['batch_no'])->order('id')->column('id');
    check(count($ids) === 6, 'Duplicate expansion or missing combinations');

    $refs = array_map(static fn ($id) => 'smart_edit:task:' . $id, $ids);
    $members = Db::name('work_item')->where(['tenant_id' => 3, 'user_id' => 8, 'type' => 'video', 'group_id' => 10])
        ->whereIn('source_ref', $refs)->count();
    check($members === 6, 'Replayed expansion did not create exactly one membership per combination');
    check(Library::lists(3, 8, 1, 20, '', 10)['count'] === 0, 'Unfinished outputs appeared in group');

    // The standard worker may refresh a task without an HTTP tenant session.
    Task::where('id', $ids[0])->update([
        'provider_job_id' => 'fixture-job', 'status' => Task::STATUS_PROCESSING,
        'poll_attempts' => Tasks::MAX_POLL_ATTEMPTS,
    ]);
    request()->tenantId = null;
    request()->source = null;
    Tasks::refresh((int)$ids[0]);
    Tasks::refresh((int)$ids[0]);
    balance(70);
    request()->tenantId = 3;
    request()->source = AdminTerminalEnum::USER;
    Tasks::retry(Task::findOrFail($ids[0]));
    balance(70);
    Tasks::cancel(3, 8, (int)$ids[0]);
    balance(70);
    Task::where('id', $ids[1])->update(['status' => Task::STATUS_PROCESSING, 'provider_job_id' => 'success-job']);
    Tasks::refresh((int)$ids[1]);
    $published = Library::lists(3, 8, 1, 20, '', 10);
    check($published['count'] === 1 && array_column($published['lists'], 'ref') === [$refs[1]], 'Completed output missing from selected group');
    check(Library::lists(3, 8, 1, 20, '', 0)['count'] === 0, 'Grouped output also appeared in default group');
    check((int)Task::find($ids[1])['user_charge_points'] === 0, 'Settled before the group completed');
    balance(70);
    request()->tenantId = 3;
    request()->source = AdminTerminalEnum::USER;
    foreach (array_slice($ids, 2) as $id) {
        Tasks::cancel(3, 8, (int)$id);
    }
    balance(95);
    check((int)Task::find($ids[1])['user_charge_points'] === 5, 'Settled amount should use the original unit price');
    $settled = Group::where('batch_no', $group['batch_no'])->findOrFail()['config_json'];
    check($settled['reserved_points'] === 0 && $settled['settled_points'] === 5, 'Group did not settle exactly once');
    Groups::delete(3, 8, $group['batch_no']);
    balance(95);
    check(Db::name('user_account_log')->count() === 6, 'Group should have a single final settlement');

    $pending = Groups::create(3, 8, array_replace($groupInput, ['expected_unit_points' => 9]));
    $pendingGroup = Group::where('batch_no', $pending['batch_no'])->findOrFail();
    balance(41);
    Groups::failPending($pendingGroup, 'DUBBING_FAILED', 'fixture');
    Groups::failPending($pendingGroup, 'DUBBING_FAILED', 'duplicate');
    balance(95);
    foreach ([[], ['work_group_id' => 0]] as $selection) {
        $defaultTask = Tasks::generate(3, 8, ['clips' => $clips, 'expected_unit_points' => 9] + $selection);
        $ref = 'smart_edit:task:' . $defaultTask['id'];
        check(!Db::name('work_item')->where('source_ref', $ref)->count(), 'Default selection wrote custom membership');
        Tasks::cancel(3, 8, (int)$defaultTask['id']);
    }
    balance(95);
    $queued = Groups::create(3, 8, array_replace($groupInput, ['expected_unit_points' => 9]));
    Db::name('work_group')->where('id', 10)->update(['delete_time' => time()]);
    $deletedGroupJob = new SmartEditGroupExpandJob([
        'batch_no' => $queued['batch_no'], 'attempt' => SmartEditGroupExpandJob::MAX_ATTEMPTS - 1,
    ]);
    $deletedGroupJob->handle();
    check(Group::where('batch_no', $queued['batch_no'])->value('status') === Group::STATUS_FAILED, 'Deleted output group accepted at expansion');
    check(Task::where('batch_no', $queued['batch_no'])->count() === 0 && Db::name('work_item')->count() === 7, 'Failed expansion left partial results');
    balance(95);
    echo "PASS smart-edit billing, owned output groups, retries, batch expansion and completed group listing\n";
}
