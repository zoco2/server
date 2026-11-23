<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchAssets;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\storage\StorageConfigService;
use PHPUnit\Framework\TestCase;
use Qiniu\Auth;
use ReflectionMethod;
use RuntimeException;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MicroVideoWorkbenchCloudReadTest extends TestCase
{
    protected function setUp(): void
    {
        class_alias(TemplateCloudReadConfig::class, StorageConfigService::class);
        class_alias(TemplateCloudReadHttp::class, OpenSafeHttp::class);
    }

    /** @dataProvider publicFiles */
    public function testPublicCopyDownloadsTheManagedObject(string $uri, string $domain, string $expected, int $limit): void
    {
        TemplateCloudReadConfig::$engines['qiniu'] = ['domain' => 'https://configured.example'];
        self::assertSame('object bytes', $this->read($uri, 'qiniu', $domain, $limit));
        self::assertSame([[$expected, $limit]], TemplateCloudReadHttp::$downloads);
    }

    public static function publicFiles(): array
    {
        return [
            'reference image' => ['uploads/photo.png', 'https://receipt.example/', 'https://receipt.example/uploads/photo.png', 10485760],
            'example video' => ['uploads/example.mp4', 'https://receipt.example/', 'https://receipt.example/uploads/example.mp4', 268435456],
            'older receipt without domain' => ['uploads/photo.png', '', 'https://configured.example/uploads/photo.png', 10485760],
        ];
    }

    public function testPrivateCopyUsesAFreshSignedUrl(): void
    {
        TemplateCloudReadConfig::$engines['qiniu'] = [
            'private_access' => true, 'access_key' => 'test-key', 'secret_key' => 'test-secret',
        ];
        $base = 'https://receipt.example/uploads/example.mp4';
        $this->read('uploads/example.mp4', 'qiniu', 'https://receipt.example', 268435456);
        self::assertCount(1, TemplateCloudReadHttp::$downloads);
        [$url, $limit] = TemplateCloudReadHttp::$downloads[0];
        self::assertSame(268435456, $limit);
        [$object, $query] = explode('?', $url, 2);
        self::assertSame($base, $object);
        parse_str($query, $signature);
        self::assertGreaterThan(time(), (int)$signature['e']);
        self::assertSame((new Auth('test-key', 'test-secret'))->sign($base.'?e='.$signature['e']), $signature['token']);
    }

    public function testUnavailablePrivateSignerDoesNotDownloadAnUnsignedUrl(): void
    {
        TemplateCloudReadConfig::$engines['aliyun'] = [
            'private_access' => true, 'access_key' => '', 'secret_key' => '',
            'domain' => 'https://configured.example', 'bucket' => 'example-media',
        ];
        try {
            $this->read('uploads/photo.png', 'aliyun', 'https://receipt.example', 10485760);
            self::fail('Expected private signing to fail');
        } catch (RuntimeException $error) {
            self::assertSame('PRIVATE_MEDIA_URL_UNAVAILABLE', $error->getMessage());
        }
        self::assertSame([], TemplateCloudReadHttp::$downloads);
    }

    private function read(string $uri, string $engine, string $domain, int $limit): string
    {
        $receipt = [
            'uri' => $uri, 'url' => 'https://previous.example/'.$uri,
            'storage_scope' => 'tenant', 'storage_engine' => $engine, 'storage_domain' => $domain,
        ];
        return (new ReflectionMethod(MicroVideoWorkbenchAssets::class, 'bytes'))->invoke(null, 41, $receipt, $limit);
    }
}

final class TemplateCloudReadConfig
{
    public static array $engines = [];

    public static function getStoredFileConfig(int $tenant, string $scope, string $engine): array
    {
        if ($tenant !== 41 || $scope !== 'tenant' || !isset(self::$engines[$engine])) {
            throw new \LogicException('Unknown tenant storage configuration');
        }
        return ['scope' => $scope, 'default' => $engine, 'engine' => [$engine => self::$engines[$engine]]];
    }
}

final class TemplateCloudReadHttp
{
    public static array $downloads = [];

    public static function get(string $url, int $limit): array
    {
        self::$downloads[] = [$url, $limit];
        return ['body' => 'object bytes'];
    }
}
