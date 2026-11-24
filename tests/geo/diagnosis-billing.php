<?php

declare(strict_types=1);

namespace app\common\service\geo {
    function curl_setopt_array($ch, array $options): bool {
        $GLOBALS['request'] = $options;
        return true;
    }
    function curl_exec($ch): string {
        $url = $GLOBALS['request'][CURLOPT_URL];
        $status = 200;
        $headers = '';
        if (str_ends_with($url, '/platforms')) {
            $data = [['id' => 'web', 'pricing' => ['webCents' => 60, 'screenshotCents' => 5]],
                ['id' => 'app', 'client' => 'mobile', 'pricing' => ['mobileCents' => 80, 'screenshotCents' => 10]]];
        } elseif (str_ends_with($url, '/runs')) {
            $GLOBALS['submissions'][] = $GLOBALS['request'][CURLOPT_HTTPHEADER];
            $status = $GLOBALS['submit_status'];
            $headers = 'X-Billing-Charged-Cents: ' . $GLOBALS['charged_cents'] . "\r\n";
            $data = $status === 200 ? ['id' => 'accepted', 'items' => [['index' => 0, 'runId' => 'r1']]]
                : ['error' => '模拟提交失败'];
        } elseif (str_ends_with($url, '/status')) {
            $headers = "X-Billing-Charged-Cents: 0\r\n";
            $data = ['terminal' => true, 'status' => 'partial_failed', 'completedChecks' => 1, 'failedChecks' => 1];
        } elseif (str_contains($url, '?page=')) {
            $data = ['items' => [['index' => 0, 'run' => ['id' => 'r1', 'status' => 'partial_failed', 'platformRuns' => [
                ['platformId' => 'web', 'status' => 'completed', 'quotedPriceCents' => 65],
                ['platformId' => 'app', 'status' => 'failed', 'quotedPriceCents' => 90],
            ]]]]];
        } else {
            $data = [];
        }
        $GLOBALS['header_size'] = strlen($headers);
        $GLOBALS['response_status'] = $status;
        return $headers . json_encode($data, JSON_THROW_ON_ERROR);
    }
    function curl_getinfo($ch, int $opt): int {
        return $opt === CURLINFO_HTTP_CODE ? $GLOBALS['response_status'] : $GLOBALS['header_size'];
    }
}

namespace {
    use app\common\service\geo\GeoDiagnosisService as Diagnosis;
    use think\App;
    use think\facade\Config;
    use think\facade\Db;
    use think\Model;

    $root = dirname(__DIR__, 2);
    require $root . '/vendor/autoload.php';
    require $root . '/vendor/topthink/framework/src/helper.php';
    require_once $root . '/app/common.php';

    function check(bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException($message);
    }
    $app = new App($root);
    Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File']]], 'cache');
    Config::set(['default' => 'test', 'connections' => ['test' => [
        'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'trigger_sql' => false,
    ]]], 'database');
    Model::setDb($app->make('db'));
    Model::maker(static fn(Model $model) => $model->setDateFormat('Y-m-d H:i:s'));
    $schema = file_get_contents($root . '/public/install/db/like.sql');
    foreach (['geo_diag_batch', 'geo_diag_run', 'geo_diag_sample', 'user_account_log', 'user', 'merchant_brand', 'user_task_run', 'user_task_run_event'] as $table) {
        check((bool)preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`la_' . $table . '`\s*\((.*?)\)\s*ENGINE/is', $schema, $matches), $table);
        preg_match_all('/^\s*`(\w+)`\s+(\w+)/m', $matches[1], $columns, PREG_SET_ORDER);
        $fields = [];
        foreach ($columns as [, $name, $type]) {
            $fields[] = "`$name` " . ($name === 'id' ? 'INTEGER PRIMARY KEY' : (str_contains($type, 'int') ? 'INTEGER' : 'TEXT'));
        }
        Db::execute("CREATE TABLE la_$table (" . implode(',', $fields) . ')');
    }
    Db::execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, tactics INTEGER, delete_time INTEGER)');
    Db::execute('CREATE TABLE la_tenant_config (id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT)');
    Db::execute('CREATE TABLE la_config (id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT)');
    Db::name('tenant')->insert(['id' => 3, 'tactics' => 0]);
    Db::name('user')->insert(['id' => 8, 'tenant_id' => 3, 'user_money' => '500']);
    Db::name('merchant_brand')->insert(['id' => 6, 'tenant_id' => 3, 'user_id' => 8, 'name' => '示例']);
    Db::name('config')->insertAll([
        ['type' => 'geo_diag', 'name' => 'provider', 'value' => '{"api_key":"fixture"}'],
        ['type' => 'business_price', 'name' => 'settings', 'value' => '{"geo_markup_percent":20}'],
    ]);

    request()->tenantId = 3;
    request()->source = \app\common\enum\AdminTerminalEnum::USER;

    $balance = static fn() => (float)Db::name('user')->where('id', 8)->value('user_money');
    $params = ['brand_id' => 6, 'questions' => ['问题'], 'platforms' => ['web', 'app'], 'screenshot' => true, 'expected_points' => 186];
    $GLOBALS['submit_status'] = 503;
    $GLOBALS['charged_cents'] = 0;
    $GLOBALS['submissions'] = [];
    check(Diagnosis::platforms(3)[0]['markup_basis_points'] === 2000, 'Display should quote the platform markup');
    $batch = Diagnosis::create(8, $params);
    check($balance() === 314.0 && $batch['reserved_points'] === 186 && !$batch['upstream_batch_id'], 'Uncertain acceptance lost reservation');
    $GLOBALS['submit_status'] = 200;
    Diagnosis::syncOne((int)$batch['id'], 8);
    check(count($GLOBALS['submissions']) === 2 && $GLOBALS['submissions'][0] === $GLOBALS['submissions'][1], 'Retry changed idempotency key');
    Db::name('config')->where('name', 'settings')->update(['value' => '{"geo_markup_percent":50}']);
    $done = Diagnosis::syncOne((int)$batch['id'], 8);
    check($done['charged_points'] === 78 && $balance() === 422.0, 'Replayed zero-cost receipt erased the initial charge or historical markup');
    Diagnosis::syncOne((int)$batch['id'], 8);
    check($balance() === 422.0 && Db::name('user_account_log')->count() === 2, 'Repeated polling moved the balance');

    foreach ([['points' => 186, 'status' => 200], ['points' => 233, 'status' => 400]] as $case) {
        $GLOBALS['submit_status'] = $case['status'];
        try {
            Diagnosis::create(8, array_replace($params, ['expected_points' => $case['points']]));
            throw new LogicException('Unexpected acceptance');
        } catch (Exception $e) {
            check(str_contains($e->getMessage(), $case['status'] === 400 ? '模拟提交失败' : '价格已变更'), $e->getMessage());
        }
        check($balance() === 422.0, 'Failed submission changed the balance');
    }
    check(count($GLOBALS['submissions']) === 3, 'Stale quote reached the upstream');
    $params['expected_points'] = 233;
    $GLOBALS['submit_status'] = 200;
    $GLOBALS['charged_cents'] = 30;
    $accepted = Diagnosis::create(8, $params);
    $done = Diagnosis::syncOne((int)$accepted['id'], 8);
    check($done['charged_points'] === 45 && (int)$done['charged_cents'] === 30 && $balance() === 377.0, 'Acceptance charge lost after polling or exceeded');
    $GLOBALS['charged_cents'] = 0;
    $free = Diagnosis::create(8, $params);
    $done = Diagnosis::syncOne((int)$free['id'], 8);
    check($done['charged_points'] === 0 && $balance() === 377.0, 'Explicit free acceptance must release the reservation');

    $runId = 41;
    Db::name('user_task_run')->insert(['id' => $runId, 'plan_id' => 1, 'tenant_id' => 3, 'user_id' => 8,
        'job_type' => 'geo_ranking', 'status' => 'running', 'workflow_step' => 'detecting',
        'job_config' => json_encode(['platforms' => $params['platforms'], 'repeat_count' => 1])]);
    $GLOBALS['submit_status'] = 503;
    $scheduled = Diagnosis::create(8, $params, $runId);
    $again = Diagnosis::create(8, $params, $runId);
    check($scheduled['id'] === $again['id'] && $balance() === 144.0, 'Scheduled replay reserved a second balance');
    Db::name('geo_diag_batch')->where('id', $scheduled['id'])->update(['next_poll_time' => 1, 'terminal' => 0]);
    $GLOBALS['submit_status'] = 200;
    Diagnosis::syncDue();
    Db::name('geo_diag_batch')->where('id', $scheduled['id'])->update(['next_poll_time' => 1]);
    Diagnosis::syncDue();
    check($balance() === 279.0, 'Scheduled settlement should charge 98 points once');
    check(Db::name('user_task_run')->where('id', $runId)->value('status') === 'failed', 'Partial result must notify the scheduled job');
    check((int)Db::name('geo_diag_batch')->where('id', $scheduled['id'])->value('next_poll_time') === 0, 'Scheduled callback must finish polling');
    Db::name('user')->where('id', 8)->update(['user_money' => '1']);
    try {
        Diagnosis::create(8, $params);
        throw new LogicException('Accepted an unfunded diagnosis');
    } catch (Exception $e) {
        check(str_contains($e->getMessage(), '不足'), $e->getMessage());
    }
    check($balance() === 1.0 && count($GLOBALS['submissions']) === 7, 'Unfunded request reached upstream');
    echo "PASS GEO tenant markup, prepayment, retry, snapshot, partial settlement and refund\n";
}
