<?php

namespace {
    require getenv('GEO_TEST_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
}

namespace think\facade {
    // Keep real SQLite transactions; replace only the framework facade binding and log sink.
    class Db {
        public static \think\DbManager $manager;
        public static function __callStatic(string $name, array $args): mixed { return self::$manager->$name(...$args); }
    }
    class Log {
        public static array $errors = [];
        public static function error(string $message): void { self::$errors[] = $message; }
    }
}

namespace app\common\model {
    class BaseModel extends \think\Model {
        protected $autoWriteTimestamp = 'int';
        public static function onBeforeInsert($model): void { $model->tenant_id = 7; }
    }
}

namespace app\common\service\geo {
    // Only the external API is simulated. Models, services and transaction handling are real.
    class GeoDiagProvider {
        public static string $mode = 'rejected';
        public static int $calls = 0;
        public static function createRun(array $request, string $key): array {
            self::$calls++;
            if (self::$mode === 'rejected') throw new GeoDiagProviderException('余额不足', 402);
            if (self::$mode === 'exception') throw new \RuntimeException('unexpected transport failure');
            if (self::$mode === 'malformed') return ['data' => []];
            return ['data' => ['id' => 'batch-123', 'status' => 'queued', 'items' => [['index' => 0, 'runId' => 'run-123']]],
                'request_id' => 'success-request', 'charged_cents' => 100, 'balance_cents' => 900];
        }
    }
    class GeoDiagProviderException extends \Exception {
        public function providerCode(): string { return 'INSUFFICIENT_BALANCE'; }
        public function requestId(): string { return 'rejected-request'; }
        public function payload(): array { return ['error' => $this->getMessage(), 'code' => $this->providerCode()]; }
    }
}

namespace {
    use app\common\service\geo\GeoDiagProvider;
    use app\common\service\geo\GeoDiagnosisService;
    use app\common\service\geo\GeoDiagnosisSubmissionException;
    use app\common\service\user_task\GeoRankingTaskJob;
    use app\common\service\user_task\UserTaskRunProgress;
    use think\facade\Db;

    function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    }
    function request(): object { return $GLOBALS['testRequest']; }
    $GLOBALS['testRequest'] = (object)['tenantId' => 99, 'source' => 'original'];
    Db::$manager = new \think\DbManager();
    Db::$manager->setConfig(['default' => 'test', 'connections' => ['test' => [
        'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_',
        'fields_strict' => true, 'trigger_sql' => false, 'break_reconnect' => false,
    ]]]);
    // Use shipped fields so accidental writes to nonexistent columns fail the regression.
    $install = file_get_contents(dirname(__DIR__, 2) . '/public/install/db/like.sql');
    foreach (['merchant_brand', 'geo_diag_batch', 'geo_diag_run', 'geo_diag_sample', 'user_task_run', 'user_task_run_event'] as $table) {
        check((bool)preg_match('/CREATE TABLE IF NOT EXISTS `la_' . $table . '` \([\s\S]*?\) ENGINE=[^\r\n]+;/', $install, $match), 'Missing schema: ' . $table);
        $sql = preg_replace('/^\s*(?:KEY|PRIMARY KEY) .+\R/m', '', $match[0]);
        $sql = preg_replace('/`id` (?:bigint|int)(?:\(\d+\))? unsigned NOT NULL AUTO_INCREMENT/', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/UNIQUE KEY `[^`]+`/', 'UNIQUE', $sql);
        $sql = preg_replace("/ COMMENT '(?:[^']|'')*'/", '', $sql);
        $sql = preg_replace('/,\s*(\) ENGINE)/', "\n$1", $sql);
        $sql = preg_replace('/\) ENGINE=[^\r\n]+;/', ');', $sql);
        Db::execute($sql);
    }
    Db::name('merchant_brand')->insert(['id' => 14, 'tenant_id' => 7, 'user_id' => 23, 'name' => '测试品牌']);
    $params = ['brand_id' => 14, 'questions' => ['品牌怎么样？'], 'platforms' => ['doubao'], 'repeat_count' => 1];

    // Manual submission: API rejection is returned to the caller, but the failed history survives.
    try { GeoDiagnosisService::create(23, $params); throw new RuntimeException('Expected business rejection'); }
    catch (GeoDiagnosisSubmissionException $e) {
        check($e->getMessage() === '余额不足' && $e->batchId() > 0, 'Must retain rejection and batch ID');
        $manualId = $e->batchId();
    }
    $list = GeoDiagnosisService::lists([], 23);
    check($list['count'] === 1 && $list['lists'][0]['status'] === 'failed', 'Rejected detection must be visible in list');
    $failed = GeoDiagnosisService::detail($manualId, 23);
    check($failed['error'] === 'INSUFFICIENT_BALANCE: 余额不足' && $failed['provider_request_id'] === 'rejected-request', 'Persist API error, code and request ID');
    check(json_decode($failed['response_json'], true)['error'] === '余额不足', 'Persist API response');
    check($failed['runs'][0]['status'] === 'failed' && $failed['runs'][0]['terminal'] === 1, 'Problem record must also fail');
    check($failed['runs'][0]['error'] === $failed['error'], 'Problem record must retain failure reason');
    check($failed['terminal'] === 1 && $failed['next_poll_time'] === 0 && $failed['finish_time'] > 0, 'Rejected submission must stop polling');
    check(GeoDiagnosisService::lists([], 24)['count'] === 0, 'Different users cannot see failed records');

    // Scheduled submission: failed batch, task result, and progress all point to the same business error.
    $config = ['brand_id' => 14, 'question' => '品牌怎么样？', 'platforms' => ['doubao'], 'repeat_count' => 1];
    $run = ['id' => 3, 'plan_id' => 1, 'scheduled_at' => time(), 'tenant_id' => 7, 'user_id' => 23, 'job_type' => 'geo_ranking', 'status' => 'queued',
        'job_config' => json_encode($config, JSON_UNESCAPED_UNICODE)];
    Db::name('user_task_run')->insert($run);
    GeoRankingTaskJob::start($run, null);
    $progress = UserTaskRunProgress::read(7, 23, 3, 0);
    check($progress['run']['status'] === 'failed' && str_contains($progress['run']['reason'], '余额不足'), 'Task must show API rejection');
    $last = end($progress['events']);
    check($last['details']['failure_reason'] === '余额不足', 'Progress must preserve specific failure');
    $batch = GeoDiagnosisService::detail($last['details']['diagnosis_id'], 23);
    check($batch['task_run_id'] === 3 && $batch['status'] === 'failed', 'Progress must refer to persisted task batch');
    check(request()->tenantId === 99 && request()->source === 'original', 'Worker context must be restored');
    GeoRankingTaskJob::start($run, null);
    check(Db::name('geo_diag_batch')->count() === 2 && GeoDiagProvider::$calls === 2, 'Repeated delivery must not resubmit rejected task');

    // Unexpected exceptions and invalid success responses still roll back newly created records.
    foreach (['exception', 'malformed'] as $mode) {
        GeoDiagProvider::$mode = $mode;
        try { GeoDiagnosisService::create(23, $params, 90); throw new LogicException('Expected exception'); }
        catch (Exception $e) {
            check(!($e instanceof GeoDiagnosisSubmissionException) && !($e instanceof LogicException), 'Unexpected failure must not become a business rejection');
        }
        check(Db::name('geo_diag_batch')->count() === 2 && Db::name('geo_diag_run')->count() === 2, 'Unexpected error must roll back batch and problem rows');
    }
    // Failure to persist the API error itself must also roll back, never leave an incomplete failed batch.
    Db::execute("CREATE TRIGGER fail_error_write BEFORE UPDATE ON la_geo_diag_run WHEN NEW.status = 'failed' BEGIN SELECT RAISE(ABORT, 'write failed'); END");
    GeoDiagProvider::$mode = 'rejected';
    try { GeoDiagnosisService::create(23, $params); throw new LogicException('Expected storage exception'); }
    catch (Exception $e) { check(!($e instanceof GeoDiagnosisSubmissionException) && !($e instanceof LogicException), 'Storage error must propagate'); }
    check(Db::name('geo_diag_batch')->count() === 2, 'Storage error must roll back entire submission');
    Db::execute('DROP TRIGGER fail_error_write');

    GeoDiagProvider::$mode = 'success';
    $success = GeoDiagnosisService::create(23, $params, 91);
    check($success['status'] === 'queued' && $success['upstream_batch_id'] === 'batch-123', 'Success path must retain provider IDs and queued state');
    check($success['runs'][0]['upstream_run_id'] === 'run-123' && $success['next_poll_time'] > 0, 'Success must remain pollable');
    check(Db::name('geo_diag_batch')->count() === 3, 'Success must add exactly one batch');
    echo "PASS: rejected API responses persist for manual/scheduled detection; unexpected and database errors roll back; success unchanged\n";
}
