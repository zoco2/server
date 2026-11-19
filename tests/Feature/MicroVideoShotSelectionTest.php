<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MicroVideoShotSelectionTest extends TestCase
{
    private function model(int $duration, string $method, string $quality = '720P'): array
    {
        $spec = ['duration' => $duration, 'quality' => $quality, 'ratio' => '9:16', 'generation_method' => $method];
        return ['specs' => [$spec], 'max_reference_images' => 4, 'start_end_specs' => $method === 'image_to_video' ? [] : [$spec]];
    }

    private function segment(string $model, int $duration, string $quality = '720P'): array
    {
        return ['model_id' => $model, 'quality' => $quality, 'duration' => $duration, 'title' => '咖啡',
            'prompt' => '咖啡从壶嘴倒入杯中。', 'start_state' => '空杯', 'end_state' => '满杯',
            'continuity' => '同一只杯子', 'image_indices' => [0]];
    }

    public function testIndependentModelsPreservePlainPromptsAndShotOrder(): void
    {
        $segments = [$this->segment('a', 8), $this->segment('b', 5, '1080P')];
        $models = ['a' => $this->model(8, 'image_reference'), 'b' => $this->model(5, 'image_to_video', '1080P')];
        $shots = MicroVideoPolicy::workbenchShots($segments, $models, '9:16');
        self::assertSame(['a', 'b'], array_column($shots, 'model_id'));
        self::assertSame(['720P', '1080P'], array_column($shots, 'quality'));
        self::assertSame([true, false], array_column($shots, 'supports_start_end'));
        self::assertSame([4, 1], array_column($shots, 'max_reference_images'));
        $rows = MicroVideoPolicy::authoredSegments($segments, $shots, 2);
        self::assertSame([8, 5], array_column($rows, 'duration'));
        self::assertSame([1, 2], array_column($rows, 'sort'));
        self::assertSame(array_column($segments, 'prompt'), array_column($rows, 'prompt'));
        $segments[1]['image_indices'] = [0, 1];
        $this->expectException(InvalidArgumentException::class);
        MicroVideoPolicy::authoredSegments($segments, $shots, 2);
    }

    public function testSavingAPlanCannotChangeTheConfirmedShotSpecification(): void
    {
        $segment = $this->segment('a', 8);
        $model = $this->model(8, 'image_reference');
        $shots = MicroVideoPolicy::workbenchShots([$segment], ['a' => $model], '9:16');
        $segment['quality'] = '1080P';
        $this->expectExceptionMessage('分镜分辨率已变化');
        MicroVideoPolicy::authoredSegments([$segment], $shots, 1);
    }

    public function testASupportedDurationOnAnotherModelDoesNotAuthorizeTheShot(): void
    {
        $models = ['a' => $this->model(8, 'image_reference'), 'b' => $this->model(5, 'image_reference')];
        $this->expectExceptionMessage('分镜 2');
        MicroVideoPolicy::workbenchShots([$this->segment('a', 8), $this->segment('b', 8)], $models, '9:16');
    }

    public function testAnUnavailableShotResolutionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MicroVideoPolicy::workbenchShots([$this->segment('a', 5, '1080P')], ['a' => $this->model(5, 'image_reference')], '9:16');
    }
}
