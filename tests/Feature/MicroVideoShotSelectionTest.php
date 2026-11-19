<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MicroVideoShotSelectionTest extends TestCase
{
    private function model(int $duration, string $method): array
    {
        $spec = ['duration' => $duration, 'quality' => '720P', 'ratio' => '9:16', 'generation_method' => $method];
        return ['specs' => [$spec], 'max_reference_images' => 4, 'start_end_specs' => $method === 'image_to_video' ? [] : [$spec]];
    }

    private function segment(string $model, int $duration): array
    {
        return ['model_id' => $model, 'duration' => $duration, 'title' => '咖啡',
            'prompt' => '咖啡从壶嘴倒入杯中。', 'start_state' => '空杯', 'end_state' => '满杯',
            'continuity' => '同一只杯子', 'image_indices' => [0]];
    }

    public function testIndependentModelsPreservePlainPromptsAndShotOrder(): void
    {
        $segments = [$this->segment('a', 8), $this->segment('b', 5)];
        $models = ['a' => $this->model(8, 'image_reference'), 'b' => $this->model(5, 'image_to_video')];
        $shots = MicroVideoPolicy::workbenchShots($segments, $models, '720P', '9:16');
        self::assertSame(['a', 'b'], array_column($shots, 'model_id'));
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

    public function testASupportedDurationOnAnotherModelDoesNotAuthorizeTheShot(): void
    {
        $models = ['a' => $this->model(8, 'image_reference'), 'b' => $this->model(5, 'image_reference')];
        $this->expectExceptionMessage('分镜 2');
        MicroVideoPolicy::workbenchShots([$this->segment('a', 8), $this->segment('b', 8)], $models, '720P', '9:16');
    }

    public function testAnUnavailableGlobalResolutionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MicroVideoPolicy::workbenchShots([$this->segment('a', 5)], ['a' => $this->model(5, 'image_reference')], '1080P', '9:16');
    }
}
