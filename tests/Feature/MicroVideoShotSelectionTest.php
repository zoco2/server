<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoPolicy;
use app\common\service\app\aigc_micro_video\MicroVideoFramePolicy;
use app\common\service\power\MarketGenerationRequest;
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

    public function testAudioOrderAndFrameAvailabilityAreIndependentForEachShot(): void
    {
        $model = $this->model(8, 'image_reference');
        $model['specs'][0]['max_reference_audios'] = 2;
        $plain = $this->segment('wan', 8);
        $withAudio = $plain + ['audio_ids'=>['42', 17]];
        $shots = MicroVideoPolicy::workbenchShots([$withAudio, $plain], ['wan'=>$model], '9:16');
        self::assertSame([42, 17], $shots[0]['audio_ids']);
        self::assertFalse($shots[0]['supports_start_end']);
        self::assertArrayNotHasKey('audio_ids', $shots[1]);
        self::assertTrue($shots[1]['supports_start_end']);
        $model['specs'][0]['max_reference_audios'] = 0;
        $this->expectException(InvalidArgumentException::class);
        MicroVideoPolicy::workbenchShots([$withAudio], ['wan'=>$model], '9:16');
    }

    public function testEditorMentionsUseTheSelectedAudioOrderEvenWithDuplicateNames(): void
    {
        $prompt = '先用@音频「17·旁白.wav」，再用@音频「42·旁白.wav」。';
        self::assertSame('先用@音频2，再用@音频1。', MicroVideoPolicy::audioMentionPrompt($prompt, [42,17]));
        self::assertSame($prompt, MicroVideoPolicy::audioMentionPrompt($prompt, []));
    }

    public function testWanReferencePayloadIncludesOnlySelectedAudioInOrder(): void
    {
        $schema = ['type'=>'array', 'items'=>['type'=>'string']];
        $snapshot = ['market_metadata'=>['params_schema'=>['reference_image_urls'=>$schema,'reference_audio_urls'=>$schema],
            'material_capabilities'=>['modes'=>[['code'=>'', 'view_key'=>':reference', 'fields'=>[
                ['field'=>'reference_image_urls','kind'=>'image','role'=>'reference','maximum'=>10],
                ['field'=>'reference_audio_urls','kind'=>'audio','role'=>'reference','maximum'=>5],
            ]]]]]];
        $images = MicroVideoFramePolicy::assets('reference', ['https://media.example/car.png'], 'omni_reference');
        $audio = static fn(string $name) => ['type'=>'audio','role'=>'reference_audio','url'=>'https://media.example/'.$name];
        $request = ['generation_method'=>'omni_reference', 'reference_assets'=>[...$images,$audio('second.wav'),$audio('first.mp3')]];
        $payload = MarketGenerationRequest::payload($snapshot, $request);
        self::assertSame(['https://media.example/second.wav','https://media.example/first.mp3'], $payload['reference_audio_urls']);
        self::assertSame(['https://media.example/car.png'], $payload['reference_image_urls']);
        $request['reference_assets'] = $images;
        self::assertArrayNotHasKey('reference_audio_urls', MarketGenerationRequest::payload($snapshot, $request));
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
