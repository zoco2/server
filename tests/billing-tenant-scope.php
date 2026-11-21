<?php

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    function request() { return $GLOBALS['pollRequest']; }
    function env($key, $default = null) { return $default; }
    function generate_sn(...$unused): string { return bin2hex(random_bytes(12)); }
}

namespace app\common\service\point_alert {
    final class AlertService { public static function touch(int $tenant, int $user): void {} }
}

namespace {
    use app\common\enum\AdminTerminalEnum;
    use app\common\model\geo\GeoOrder;
    use app\common\model\geo\GeoDiagBatch;
    use app\common\model\smart_edit\SmartEditTask;
    use app\common\service\point\UserPointService;
    use app\common\service\pricing\BillingTenantScope;
    use think\Container;
    use think\DbManager;

    $request = new class {
        public $tenantId = null;
        public $tenantSn = null;
        public $source = 'previous';
        public $tenantResolveMode = null;
        public function header($key) { return null; }
    };
    $GLOBALS['pollRequest'] = $request;
    Container::getInstance()->instance('request', $request);
    $db = new DbManager();
    Container::getInstance()->instance(DbManager::class, $db);
    $db->setConfig(['default' => 'test', 'connections' => ['test' => [
        'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true,
    ]]]);
    $db->execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, sn TEXT, tactics INTEGER, delete_time INTEGER)');
    $db->name('tenant')->insertAll([
        ['id' => 10, 'sn' => 'shared', 'tactics' => 0],
        ['id' => 20, 'sn' => 'split', 'tactics' => 1],
        ['id' => 30, 'sn' => 'uninstalled', 'tactics' => 1],
    ]);
    foreach (['' => 10, '_split' => 20] as $suffix => $tenant) {
        $db->execute("CREATE TABLE la_geo_order$suffix (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, status INTEGER,
            create_time INTEGER, update_time INTEGER)");
        $db->execute("CREATE TABLE la_geo_diag_batch$suffix (id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, create_time INTEGER, update_time INTEGER)");
        $db->execute("CREATE TABLE la_smart_edit_task$suffix (id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, params_json TEXT,
            delete_time INTEGER, create_time INTEGER, update_time INTEGER)");
        $db->execute("CREATE TABLE la_user$suffix (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_money REAL, delete_time INTEGER,
            create_time INTEGER, update_time INTEGER)");
        $db->execute("CREATE TABLE la_user_account_log$suffix (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER,
            sn TEXT, change_object INTEGER, change_type INTEGER, action INTEGER, change_amount REAL, left_amount REAL,
            source_sn TEXT, remark TEXT, extra TEXT, delete_time INTEGER, create_time INTEGER, update_time INTEGER)");
        $db->name('geo_order' . $suffix)->insert(['id' => 1, 'user_id' => 1, 'tenant_id' => $tenant]);
        foreach (['geo_diag_batch', 'smart_edit_task'] as $name) {
            $db->name($name . $suffix)->insert(['id' => 1, 'tenant_id' => $tenant, 'status' => 'pending']);
        }
        $db->name('user' . $suffix)->insert(['id' => 1, 'tenant_id' => $tenant, 'user_money' => 100]);
    }
    $visited = [];
    BillingTenantScope::each('geo_order', function (int $id) use (&$visited, $db) {
        $visited[] = $id;
        $order = GeoOrder::findOrFail(1);
        if ((int)$order['tenant_id'] !== $id || request()->source !== AdminTerminalEnum::USER) {
            throw new RuntimeException('Worker selected the wrong order');
        }
        $db->transaction(static function () use ($order, $id) {
            UserPointService::consume(1, 2 * $id, 'same-charge', '同号用户预扣');
            UserPointService::refund(1, $id, 'same-source', '同号用户差额退款');
            $order->save(['status' => GeoOrder::STATUS_PENDING]);
            $order->save(['status' => $id === 10 ? GeoOrder::STATUS_DONE : GeoOrder::STATUS_REJECTED]);
            GeoDiagBatch::findOrFail(1)->save(['status' => $id === 10 ? 'completed' : 'failed']);
            $task = SmartEditTask::findOrFail(1);
            $task->save(['status' => 'submitting', 'params_json' => ['billing_attempt' => 1]]);
            $task->save(['status' => $id === 10 ? 'success' : 'failed']);
        });
    });
    if ($visited !== [10, 20] || $request->source !== 'previous' || $request->tenantId !== null) {
        throw new RuntimeException('Tenant enumeration or context restoration failed');
    }
    foreach (['' => 10, '_split' => 20] as $suffix => $tenant) {
        $balance = $db->name('user' . $suffix)->where('id', 1)->value('user_money');
        $logs = $db->name('user_account_log' . $suffix)->select()->toArray();
        if ((int)$balance !== 100 - $tenant || count($logs) !== 2
            || (int)$logs[0]['tenant_id'] !== $tenant || (int)$logs[1]['tenant_id'] !== $tenant
            || (int)$logs[1]['left_amount'] !== (int)$balance) {
            throw new RuntimeException('Refund touched the wrong tenant wallet or ledger');
        }
        if ((int)$db->name('geo_order' . $suffix)->value('status') !== ($tenant === 10 ? 5 : 2)
            || $db->name('geo_diag_batch' . $suffix)->value('status') !== ($tenant === 10 ? 'completed' : 'failed')
            || $db->name('smart_edit_task' . $suffix)->value('status') !== ($tenant === 10 ? 'success' : 'failed')) {
            throw new RuntimeException('Retry or terminal state written into the wrong table');
        }
    }
    echo "PASS: shared and split tenants with the same order ID, user ID and refund source use independent wallets\n";
}
