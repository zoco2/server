<?php

declare(strict_types=1);

// 运行方式：启用 pdo_sqlite 后执行 php tests/nfc-code/generation.php [vendor/autoload.php]。
// 可选参数用于复用其他检出目录的 Composer 依赖，应用类仍从当前被测目录加载。
// 测试只注册 SQLite 内存连接，不初始化应用配置，不连接站点数据库。
namespace app\common\service\merchant {
    /**
     * 覆盖服务所在命名空间内的随机函数，按测试序列强制生成碰撞和无效候选。
     * PHP 会优先调用同命名空间函数；序列耗尽后回退到全局安全随机函数。
     * 这样能验证真实服务的补码流程，而不用修改生产代码或依赖随机碰撞概率。
     */
    function random_int(int $min, int $max): int
    {
        if ($GLOBALS['nfc_random_values'] !== []) {
            $value = array_shift($GLOBALS['nfc_random_values']);
            if ($value < $min || $value > $max) {
                throw new \RuntimeException('Injected random value is out of bounds');
            }
            return $value;
        }
        return \random_int($min, $max);
    }
}

namespace {
    use app\common\enum\AdminTerminalEnum;
    use app\common\model\merchant\NfcCode;
    use app\common\service\merchant\NfcCodeService;

    // Composer 自动加载器可来自共享 vendor，但业务类明确映射回本次测试的代码目录。
    $root = dirname(__DIR__, 2);
    $loader = require $argv[1] ?? $root . '/vendor/autoload.php';
    $loader->setPsr4('app\\', $root . '/app');
    require_once dirname((new ReflectionClass(think\App::class))->getFileName(), 2) . '/helper.php';
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('This test requires pdo_sqlite');
    }

    // 只创建框架容器和数据库管理器，跳过应用初始化，避免读取站点连接配置。
    // DbManager 注册 ORM 模型连接，后续断言通过真实 NfcCode 和 BaseModel 执行。
    new think\App($root);
    $manager = new think\DbManager();
    $manager->setConfig([
        'default' => 'nfc_test',
        'connections' => ['nfc_test' => [
            'type' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'la_',
            'fields_strict' => true,
            'fields_cache' => false,
        ]],
    ]);
    // 模拟平台请求上下文，验证库存模型在当前阶段按统一库存查询。
    request()->source = AdminTerminalEnum::PLATFORM;
    request()->tenantId = null;
    $connection = $manager->connect();
    // SQLite 测试表只保留当前阶段字段和唯一约束；MySQL 建表及驱动重试需另外联调。
    $connection->execute('CREATE TABLE la_merchant_nfc_code (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        batch_no TEXT NOT NULL,
        tenant_id INTEGER DEFAULT NULL,
        created_admin_id INTEGER NOT NULL,
        tenant_assigned_admin_id INTEGER DEFAULT NULL,
        tenant_assigned_at INTEGER DEFAULT NULL,
        create_time INTEGER NOT NULL,
        update_time INTEGER NOT NULL
    )');
    // 每个数值是服务字符表中的下标，下面的碰撞用例会填充确定的序列。
    $GLOBALS['nfc_random_values'] = [];

    /** 严格比较值及类型，失败时输出用例说明和实际值，便于定位分页及时间戳问题。 */
    function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
    }

    /** 要求业务参数被 DomainException 拒绝；其他异常不吞掉，以免错误地判为通过。 */
    function rejected(callable $action, string $message): void
    {
        try {
            $action();
        } catch (DomainException) {
            return;
        }
        throw new RuntimeException($message);
    }

    // 边界和原始类型校验：非法数量或管理员必须在任何写入发生前被拒绝。
    foreach ([null, 0, -1, 1001, '1.5', 1.5, true, [], 'foo'] as $quantity) {
        rejected(fn () => NfcCodeService::generate(7, $quantity), 'Invalid quantity accepted');
    }
    rejected(fn () => NfcCodeService::generate(0, 1), 'Missing administrator accepted');
    same(0, NfcCode::count(), 'Validation must not create rows');

    // 先放入一个已存在编号，用来证明服务会查库排除它，而不是碰到唯一键后直接失败。
    NfcCode::query()->insert([
        'code' => 'A00001', 'batch_no' => 'existing', 'created_admin_id' => 5,
        'create_time' => 1, 'update_time' => 1,
    ]);
    // 固定序列依次包含已存在编号、纯字母、纯数字和批内重复候选。
    // 三个成功结果必须补齐到 A00004，同时保留已有库存且耗尽全部测试序列。
    foreach (str_split('A00001AAAAAA000000A00002A00002A00003A00004') as $char) {
        $GLOBALS['nfc_random_values'][] = strpos('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ', $char);
    }
    $first = NfcCodeService::generate(7, '3');
    same([], $GLOBALS['nfc_random_values'], 'Collision fixture must be consumed');
    same(3, $first['quantity'], 'Requested batch size');
    same(['A00002', 'A00003', 'A00004'], NfcCode::where('batch_no', $first['batch_no'])
        ->order('code')->column('code'), 'Existing and duplicate candidates excluded');
    same(1, NfcCode::where('code', 'A00001')->count(), 'Existing stock preserved');

    // 最大批量会跨越多个查询和写入分块，检查数量、格式、批内唯一性与创建来源。
    $second = NfcCodeService::generate(8, 1000);
    same(1000, $second['quantity'], 'Maximum batch size');
    $rows = NfcCode::where('batch_no', $second['batch_no'])->select()->toArray();
    same(1000, count($rows), 'Full batch persisted');
    same(1000, count(array_unique(array_column($rows, 'code'))), 'Batch codes unique');
    foreach ($rows as $row) {
        same(1, preg_match('/\A(?=.*[A-Z])(?=.*[0-9])[A-Z0-9]{6}\z/', $row['code']), 'Code format');
        same(8, (int)$row['created_admin_id'], 'Creating administrator retained');
    }

    // 通过小批次验证“总数先过滤再分页”、主键倒序及 create_time 整数类型。
    $page = NfcCodeService::adminLists(['batch_no' => $first['batch_no'], 'page_no' => 2, 'page_size' => 2]);
    same(3, $page['count'], 'Count applies filters before pagination');
    same(['A00002'], array_column($page['lists'], 'code'), 'Descending ID pagination');
    same(2, $page['page_no'], 'Page number');
    same(2, $page['page_size'], 'Page size');
    same(true, is_int($page['lists'][0]['create_time']), 'Timestamp remains an integer');
    // 编号和批次可同时筛选；小写编号和输入两端空白由服务规范化。
    $exact = NfcCodeService::adminLists(['code' => ' a00003 ', 'batch_no' => ' ' . $first['batch_no'] . ' ']);
    same(['A00003'], array_column($exact['lists'], 'code'), 'Normalized exact code and batch filters');
    same(0, NfcCodeService::adminLists(['code' => 'A00003', 'batch_no' => $second['batch_no']])['count'], 'Filters intersect');
    // 每页最多 100 条，并要求非法编号、页码和数量返回业务参数错误。
    $limited = NfcCodeService::adminLists(['batch_no' => $second['batch_no'], 'page_size' => 1000]);
    same(100, count($limited['lists']), 'Page size capped at 100');
    foreach ([['code' => '123456'], ['code' => 'ABCDEF'], ['code' => []], ['page_no' => 0], ['page_size' => -1]] as $input) {
        rejected(fn () => NfcCodeService::adminLists($input), 'Invalid list input accepted');
    }

    // 让管理员 99 的前 250 条正常插入，第 251 条由触发器主动失败。
    // 这会命中第二个写入分块，验证前一块也被同一事务回滚，不留下残缺批次。
    // 该用例验证原子性，不模拟 MySQL 的 1062 唯一键冲突或 1213 死锁重试。
    $before = NfcCode::count();
    $connection->execute("CREATE TRIGGER fail_second_chunk BEFORE INSERT ON la_merchant_nfc_code
        WHEN NEW.created_admin_id = 99
          AND (SELECT COUNT(*) FROM la_merchant_nfc_code WHERE created_admin_id = 99) >= 250
        BEGIN SELECT RAISE(ABORT, 'simulated write failure'); END");
    try {
        NfcCodeService::generate(99, 251);
        throw new RuntimeException('Simulated storage failure was ignored');
    } catch (think\db\exception\PDOException $error) {
        same(true, str_contains($error->getMessage(), 'simulated write failure'), 'Expected storage failure');
    }
    same($before, NfcCode::count(), 'Whole batch rolls back after a later chunk fails');
    same(0, NfcCode::where('created_admin_id', 99)->count(), 'No partial stock created');

    echo "PASS NFC validation, mixed codes, DB collision replenishment, pagination and atomic batch rollback\n";
}
