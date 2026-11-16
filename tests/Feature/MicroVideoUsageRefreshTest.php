<?php
namespace Tests\Feature;

use app\common\model\ai\AiConsumptionLog;
use app\common\service\app\aigc_micro_video\MicroVideoSubmission;
use app\common\service\point\PointService;
use app\common\service\power\MarketVideoRuntimeService as Runtime;
use app\common\service\power\MicroVideoUsageTransport as Transport;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MicroVideoUsageRefreshTest extends TestCase
{
    private const VIDEOS = [['video_uri' => 'https://fixture.invalid/completed.mp4']];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite') || !extension_loaded('curl')) self::markTestSkipped('Requires pdo_sqlite and curl');
        require dirname(__DIR__) . '/fixtures/micro_video_usage_transport.php';
        (new \think\App(dirname(__DIR__, 2) . '/'))->initialize();
        Config::set(['default' => 'usage_fixture', 'connections' => ['usage_fixture' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        Db::execute('CREATE TABLE la_ai_app_task (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER,
            app_code TEXT, task_no TEXT, status TEXT, progress INTEGER DEFAULT 0, result_summary TEXT,
            actual_tenant_cost REAL DEFAULT 0, actual_user_price REAL DEFAULT 0, finish_time INTEGER DEFAULT 0,
            create_time INTEGER DEFAULT 0, update_time INTEGER DEFAULT 0)');
        Db::execute('CREATE TABLE la_ai_consumption_log (id INTEGER PRIMARY KEY, app_task_id INTEGER, tenant_id INTEGER,
            user_id INTEGER, app_code TEXT, consume_no TEXT, run_status TEXT, billing_status TEXT,
            upstream_task_id TEXT, upstream_request_id TEXT, price_snapshot TEXT, usage_snapshot TEXT,
            response_summary TEXT, quantity REAL, reserved_tenant_cost REAL DEFAULT 0, reserved_user_price REAL DEFAULT 0,
            actual_tenant_cost REAL DEFAULT 0, actual_user_price REAL DEFAULT 0, error_code TEXT DEFAULT \'\',
            error_message TEXT DEFAULT \'\', refresh_requested_at INTEGER DEFAULT 0, finish_time INTEGER DEFAULT 0,
            create_time INTEGER DEFAULT 0, update_time INTEGER DEFAULT 0)');
        Db::execute('CREATE TABLE la_ai_consumption_event (id INTEGER PRIMARY KEY AUTOINCREMENT, consumption_id INTEGER,
            event_type TEXT, event_status TEXT, attempt_no INTEGER, payload_summary TEXT, payload_ciphertext TEXT,
            http_status INTEGER, elapsed_ms INTEGER, create_time INTEGER, update_time INTEGER)');
        Db::name('ai_app_task')->insert(['id' => 1, 'tenant_id' => 1, 'user_id' => 1, 'app_code' => 'aigc_micro_video',
            'task_no' => 'fixture-app', 'status' => 'running']);
        Db::name('ai_consumption_log')->insert(['id' => 1, 'app_task_id' => 1, 'tenant_id' => 1, 'user_id' => 1,
            'app_code' => 'aigc_micro_video', 'consume_no' => 'fixture-consume', 'run_status' => 'success',
            'billing_status' => 'reserved', 'upstream_task_id' => 'accepted-video', 'upstream_request_id' => 'accepted-request',
            'quantity' => 30, 'reserved_tenant_cost' => 4572, 'reserved_user_price' => 4572,
            'price_snapshot' => json_encode(['resource_type' => 'model', 'usage_unit' => 'output_second',
                'requires_actual_duration' => true, 'quantity' => 30, 'platform_price' => 152.4, 'tenant_price' => 152.4,
                'billing_meter' => ['version' => 1, 'basis' => 'output', 'round_up' => true]]),
            'usage_snapshot' => json_encode(['actual_quantity' => 0, 'settlement_basis' => 'awaiting_actual_usage']),
            'response_summary' => json_encode(['video_count' => 1, 'videos' => self::VIDEOS])]);
    }

    private function row(): array { return AiConsumptionLog::findOrEmpty(1)->toArray(); }

    private function assertOnlyQueries(int $count): void
    {
        self::assertCount($count, Transport::$requests);
        foreach (Transport::$requests as $request) {
            self::assertSame('https://fixture.invalid/api/v1/tasks/accepted-video', $request[CURLOPT_URL]);
            self::assertArrayNotHasKey(CURLOPT_POST, $request);
        }
        self::assertSame(1, Db::name('ai_consumption_log')->count());
    }

    public function testCachedVideoPollsDelayedDurationAndSettlesExactlyOnce(): void
    {
        Transport::$responses = [['data' => ['status' => 'completed', 'usage' => ['output_seconds' => 30.02]]]];
        $result = Runtime::refresh(1);
        $row = $this->row();
        self::assertSame('settled', $row['billing_status']);
        self::assertSame(30.02, $row['usage_snapshot']['actual_quantity']);
        self::assertSame(4576.0, (float)$row['actual_user_price']);
        self::assertSame('success', $result['status']);
        self::assertSame(self::VIDEOS, $result['videos']);
        self::assertCount(1, PointService::$charges);
        self::assertSame(4576.0, PointService::$charges[0][5]);
        Runtime::refresh(1);
        self::assertCount(1, PointService::$charges);
        $this->assertOnlyQueries(1);
    }

    public function testMissingUsageKeepsReservationAndPollsAgainWithoutDeliveringVideo(): void
    {
        Transport::$responses = array_fill(0, 2, ['data' => ['status' => 'completed', 'usage' => ['points_cost' => 4576]]]);
        for ($i = 0; $i < 2; $i++) {
            $result = Runtime::refresh(1);
            self::assertSame('running', $result['status']);
            self::assertSame([], $result['videos']);
            self::assertSame('reserved', $this->row()['billing_status']);
            self::assertSame(self::VIDEOS, $this->row()['response_summary']['videos']);
        }
        self::assertSame([], PointService::$charges);
        $this->assertOnlyQueries(2);
    }

    public function testQueryFailureRetainsResultAndNextRefreshRecovers(): void
    {
        Transport::$responses = [new \RuntimeException('Fixture query timeout'), ['data' => ['usage' => ['duration' => 29.5]]]];
        self::assertSame('running', Runtime::refresh(1)['status']);
        self::assertSame('reserved', $this->row()['billing_status']);
        self::assertSame(self::VIDEOS, $this->row()['response_summary']['videos']);
        self::assertSame('refresh_retrying', $this->row()['error_code']);
        self::assertSame('success', Runtime::refresh(1)['status']);
        self::assertSame('', $this->row()['error_code']);
        self::assertCount(1, PointService::$charges);
        $this->assertOnlyQueries(2);
    }

    public function testReceiptAlreadyContainingDurationRecoversWithoutQuery(): void
    {
        MicroVideoSubmission::capture(1, ['data' => ['usage' => ['output_seconds' => 30.02]]], 'accepted-video', 'accepted-request');
        self::assertSame('success', Runtime::refresh(1)['status']);
        self::assertSame('settled', $this->row()['billing_status']);
        self::assertCount(1, PointService::$charges);
        $this->assertOnlyQueries(0);
    }

    public function testFailedSettlementRetainsReceiptForRecoveryWithoutAnotherQuery(): void
    {
        Transport::$responses = [['data' => ['usage' => ['output_seconds' => 30.02]]]];
        PointService::$fail = true;
        self::assertSame('running', Runtime::refresh(1)['status']);
        self::assertSame('reserved', $this->row()['billing_status']);
        self::assertNotNull(MicroVideoSubmission::receipt($this->row()));
        PointService::$fail = false;
        self::assertSame('success', Runtime::refresh(1)['status']);
        self::assertCount(1, PointService::$charges);
        $this->assertOnlyQueries(1);
    }

    public function testAwaitingUsageKeepsProviderReceipt(): void
    {
        $receipt = ['data' => ['status' => 'completed', 'usage' => ['points_cost' => 4576]]];
        MicroVideoSubmission::capture(1, $receipt, 'accepted-video', 'accepted-request');
        $settle = new \ReflectionMethod(Runtime::class, 'settle');
        $settle->setAccessible(true);
        $settle->invoke(null, 1, self::VIDEOS, 'accepted-request', 'accepted-video', $receipt);
        self::assertSame($receipt, MicroVideoSubmission::receipt($this->row()));
        self::assertSame('reserved', $this->row()['billing_status']);
        self::assertSame([], PointService::$charges);
    }

    public function testPendingTokenUsageAlsoContinuesPolling(): void
    {
        $snapshot = $this->row()['price_snapshot'];
        unset($snapshot['billing_meter']);
        $snapshot = array_replace($snapshot, ['usage_unit' => 'token', 'usage_unit_size' => 1000, 'requires_actual_duration' => false]);
        Db::name('ai_consumption_log')->where('id', 1)->update(['billing_status' => 'pending_usage', 'price_snapshot' => json_encode($snapshot)]);
        Transport::$responses = [['data' => ['usage' => ['total_tokens' => 2000]]]];
        self::assertSame('success', Runtime::refresh(1)['status']);
        self::assertSame('settled', $this->row()['billing_status']);
        self::assertSame(2000.0, (float)$this->row()['usage_snapshot']['actual_quantity']);
        self::assertCount(1, PointService::$charges);
        $this->assertOnlyQueries(1);
    }

    public function testFreshVideoWithoutUsageWaitsThenUsesLaterUsageOnlyResponse(): void
    {
        Db::name('ai_consumption_log')->where('id', 1)->update(['run_status' => 'running', 'response_summary' => '{}']);
        Transport::$responses = [
            ['data' => ['status' => 'completed', 'result' => ['video_url' => self::VIDEOS[0]['video_uri']]]],
            ['data' => ['status' => 'completed', 'usage' => ['output_seconds' => 30.02]]],
        ];
        self::assertSame('running', Runtime::refresh(1)['status']);
        self::assertSame('reserved', $this->row()['billing_status']);
        $saved = $this->row()['response_summary']['videos'];
        self::assertSame(self::VIDEOS[0]['video_uri'], $saved[0]['video_uri']);
        self::assertNotNull(MicroVideoSubmission::receipt($this->row()));
        $result = Runtime::refresh(1);
        self::assertSame('success', $result['status']);
        self::assertSame($saved, $result['videos']);
        self::assertCount(1, PointService::$charges);
        $this->assertOnlyQueries(2);
    }

    public function testSynchronousReceiptWithoutUsageDoesNotDeliverBeforeSettlement(): void
    {
        Db::name('ai_consumption_log')->where('id', 1)->update(['response_summary' => '{}']);
        MicroVideoSubmission::capture(1, ['video_url' => self::VIDEOS[0]['video_uri']], '', 'accepted-request');
        $result = Runtime::refresh(1);
        self::assertSame('running', $result['status']);
        self::assertSame([], $result['videos']);
        self::assertSame('reserved', $this->row()['billing_status']);
        self::assertNotEmpty($this->row()['response_summary']['videos']);
        self::assertSame([], PointService::$charges);
        $this->assertOnlyQueries(0);
    }
}
