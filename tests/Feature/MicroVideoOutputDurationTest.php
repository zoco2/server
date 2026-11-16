<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoMedia;
use PHPUnit\Framework\TestCase;

final class MicroVideoOutputDurationTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__, 2) . '/'))->initialize();
        $binary = new \ReflectionMethod(MicroVideoMedia::class, 'binary');
        $binary->setAccessible(true);
        try { $binary->invoke(null, 'ffprobe'); }
        catch (\RuntimeException) { self::markTestSkipped('Requires ffprobe or MICRO_VIDEO_FFPROBE'); }
    }

    public function testMeasuresRealGeneratedVideoAndCleansTemporaryFiles(): void
    {
        $before = glob(sys_get_temp_dir() . '/micro-video-*');
        $bytes = file_get_contents(dirname(__DIR__) . '/open-platform/fixtures/music-export.mp4');
        self::assertSame(1.0, MicroVideoMedia::measureDuration(1, 1, 'data:video/mp4;base64,' . base64_encode($bytes)));
        self::assertSame($before, glob(sys_get_temp_dir() . '/micro-video-*'));
    }

    public function testCorruptVideoCannotSupplyBillingDurationAndIsCleanedUp(): void
    {
        $before = glob(sys_get_temp_dir() . '/micro-video-*');
        try {
            MicroVideoMedia::measureDuration(1, 1, 'data:video/mp4;base64,' . base64_encode('not a video'));
            self::fail('Corrupt output supplied a billing duration');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('视频处理失败', $error->getMessage());
        }
        self::assertSame($before, glob(sys_get_temp_dir() . '/micro-video-*'));
    }

    public function testMeasurementDoesNotFetchPrivateNetworkResultUrls(): void
    {
        $this->expectException(\app\common\service\open_platform\OpenException::class);
        MicroVideoMedia::measureDuration(1, 1, 'http://127.0.0.1/result.mp4');
    }
}
