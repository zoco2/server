<?php
// Loaded only in isolated PHPUnit processes. HTTP and wallet writes are fake;
// refresh, receipt persistence, usage parsing and settlement use the real code.
namespace app\common\service\update {
    final class UpdateSourceClient
    {
        public static function getSource(): array { return ['base_url' => 'https://fixture.invalid', 'api_key' => 'fixture']; }
        public static function sslVerify(array $source): bool { return true; }
    }
}

namespace app\common\service\power {
    final class MicroVideoUsageTransport
    {
        public static array $responses = [];
        public static array $requests = [];
    }
    function curl_init() { return new \stdClass(); }
    function curl_setopt_array($handle, array $options) { $handle->options = $options; return true; }
    function curl_exec($handle) {
        MicroVideoUsageTransport::$requests[] = $handle->options;
        if (MicroVideoUsageTransport::$responses === []) throw new \RuntimeException('Unexpected provider request');
        $response = array_shift(MicroVideoUsageTransport::$responses);
        if ($response instanceof \Throwable) throw $response;
        return json_encode($response);
    }
    function curl_errno($handle) { return 0; }
    function curl_error($handle) { return ''; }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_close($handle) {}
}

namespace app\common\service\ai {
    final class AiTaskResultStorageService
    {
        public static function transferEnabled(int $tenant, bool $force): bool { return false; }
    }
}

namespace app\common\service\point {
    final class PointService
    {
        public static array $charges = [];
        public static bool $fail = false;
        public static function assertCanConsumeAmounts(...$args): void {}
        public static function settleReservedBusinessAmountsInCurrentTransaction(...$args): void
        {
            if (self::$fail) throw new \RuntimeException('Fixture wallet unavailable');
            self::$charges[] = $args;
        }
        public static function consumeBusinessAmountsInCurrentTransaction(...$args): void
        {
            if (self::$fail) throw new \RuntimeException('Fixture wallet unavailable');
            self::$charges[] = $args;
        }
    }
}
