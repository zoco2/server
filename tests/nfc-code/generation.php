<?php

declare(strict_types=1);

// Run with php -d extension=pdo_sqlite tests/nfc-code/generation.php [vendor/autoload.php].
// Only an in-memory SQLite connection is registered. Application config is never loaded.
namespace app\common\service\merchant {
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

    $root = dirname(__DIR__, 2);
    $loader = require $argv[1] ?? $root . '/vendor/autoload.php';
    $loader->setPsr4('app\\', $root . '/app');
    require_once dirname((new ReflectionClass(think\App::class))->getFileName(), 2) . '/helper.php';
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('This test requires pdo_sqlite');
    }

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
    request()->source = AdminTerminalEnum::PLATFORM;
    request()->tenantId = null;
    $connection = $manager->connect();
    $connection->execute('CREATE TABLE la_merchant_nfc_code (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        batch_no TEXT NOT NULL,
        created_admin_id INTEGER NOT NULL,
        create_time INTEGER NOT NULL,
        update_time INTEGER NOT NULL
    )');
    $GLOBALS['nfc_random_values'] = [];

    function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
    }

    function rejected(callable $action, string $message): void
    {
        try {
            $action();
        } catch (DomainException) {
            return;
        }
        throw new RuntimeException($message);
    }

    foreach ([null, 0, -1, 1001, '1.5', 1.5, true, [], 'foo'] as $quantity) {
        rejected(fn () => NfcCodeService::generate(7, $quantity), 'Invalid quantity accepted');
    }
    rejected(fn () => NfcCodeService::generate(0, 1), 'Missing administrator accepted');
    same(0, NfcCode::count(), 'Validation must not create rows');

    NfcCode::query()->insert([
        'code' => 'A00001', 'batch_no' => 'existing', 'created_admin_id' => 5,
        'create_time' => 1, 'update_time' => 1,
    ]);
    // Force an existing DB code, invalid single-class codes and a duplicate candidate.
    // Replenishment must reach A00004 instead of relying on the UNIQUE index to fail.
    foreach (str_split('A00001AAAAAA000000A00002A00002A00003A00004') as $char) {
        $GLOBALS['nfc_random_values'][] = strpos('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ', $char);
    }
    $first = NfcCodeService::generate(7, '3');
    same([], $GLOBALS['nfc_random_values'], 'Collision fixture must be consumed');
    same(3, $first['quantity'], 'Requested batch size');
    same(['A00002', 'A00003', 'A00004'], NfcCode::where('batch_no', $first['batch_no'])
        ->order('code')->column('code'), 'Existing and duplicate candidates excluded');
    same(1, NfcCode::where('code', 'A00001')->count(), 'Existing stock preserved');

    $second = NfcCodeService::generate(8, 1000);
    same(1000, $second['quantity'], 'Maximum batch size');
    $rows = NfcCode::where('batch_no', $second['batch_no'])->select()->toArray();
    same(1000, count($rows), 'Full batch persisted');
    same(1000, count(array_unique(array_column($rows, 'code'))), 'Batch codes unique');
    foreach ($rows as $row) {
        same(1, preg_match('/\A(?=.*[A-Z])(?=.*[0-9])[A-Z0-9]{6}\z/', $row['code']), 'Code format');
        same(8, (int)$row['created_admin_id'], 'Creating administrator retained');
    }

    $page = NfcCodeService::adminLists(['batch_no' => $first['batch_no'], 'page_no' => 2, 'page_size' => 2]);
    same(3, $page['count'], 'Count applies filters before pagination');
    same(['A00002'], array_column($page['lists'], 'code'), 'Descending ID pagination');
    same(2, $page['page_no'], 'Page number');
    same(2, $page['page_size'], 'Page size');
    same(true, is_int($page['lists'][0]['create_time']), 'Timestamp remains an integer');
    $exact = NfcCodeService::adminLists(['code' => ' a00003 ', 'batch_no' => ' ' . $first['batch_no'] . ' ']);
    same(['A00003'], array_column($exact['lists'], 'code'), 'Normalized exact code and batch filters');
    same(0, NfcCodeService::adminLists(['code' => 'A00003', 'batch_no' => $second['batch_no']])['count'], 'Filters intersect');
    $limited = NfcCodeService::adminLists(['batch_no' => $second['batch_no'], 'page_size' => 1000]);
    same(100, count($limited['lists']), 'Page size capped at 100');
    foreach ([['code' => '123456'], ['code' => 'ABCDEF'], ['code' => []], ['page_no' => 0], ['page_size' => -1]] as $input) {
        rejected(fn () => NfcCodeService::adminLists($input), 'Invalid list input accepted');
    }

    // Fail the second insert chunk and prove the first 250 inserts are rolled back.
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
