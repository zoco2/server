<?php

declare(strict_types=1);

use app\common\service\geo\GeoDiagConfigService;

if (PHP_SAPI !== 'cli') exit(1);
if ($argc !== 2 || !ctype_digit($argv[1]) || (int)$argv[1] <= 0) {
    fwrite(STDERR, "用法：php scripts/import-geo-diagnosis-config.php <租户ID>\n");
    exit(2);
}
require dirname(__DIR__) . '/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();
$key = trim((string)env('geo_diag.api_key', '')) ?: trim((string)getenv('GEO_DIAG_API_KEY'));
if ($key === '') {
    fwrite(STDERR, "未找到旧 GEO 检测 API Key。\n");
    exit(1);
}
try {
    GeoDiagConfigService::save((int)$argv[1], [
        'api_key' => $key,
        'ssl_verify' => filter_var(env('geo_diag.ssl_verify', false), FILTER_VALIDATE_BOOLEAN),
    ], true);
    echo "该租户的 GEO 检测配置已导入。\n";
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $e) {
    // 数据库异常可能包含 SQL 参数，避免输出到控制台。
    fwrite(STDERR, "未导入，请检查租户是否已有配置及数据库连接。\n");
    exit(1);
}
