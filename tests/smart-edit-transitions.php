<?php

use app\common\service\smart_edit\SmartEditGroupService;
use app\common\service\smart_edit\SmartEditService;
use app\common\service\smart_edit\SmartEditTimelineBuilder as Timeline;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkTransitionResult(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$shots = SmartEditGroupService::normalizeShots([
    ['videos' => [['uri' => 'a1', 'in' => 1, 'out' => 4, 'mute' => true], ['uri' => 'a2', 'out' => 0.6]]],
    ['videos' => [['uri' => 'b', 'out' => 3]]],
    ['videos' => [['uri' => 'c', 'out' => 3]]],
    ['videos' => [['uri' => 'd', 'out' => 3]]],
]);
$keys = ['dissolve', 'flash', 'none'];
$shared = ['transitions' => Timeline::normalizeTransitions($keys, count($shots) - 1)];
foreach (SmartEditGroupService::expandShots($shots) as $index => $combo) {
    $comboConfig = SmartEditService::sharedForCombo($shared, $index + 1);
    $items = array_map(static fn(array $clip): array => ['media_url' => $clip['uri']] + $clip, $combo);
    $plan = Timeline::planTransitions($items, $comboConfig['transitions'], true);
    $timeline = Timeline::buildMerge($items, 'cover.jpg', transitionPlan: $plan);
    $mainTrack = $timeline['VideoTracks'][0];
    $mainClips = $mainTrack['VideoTrackClips'];
    $transition = ['Type' => 'Transition', 'SubType' => 'fade', 'Duration' => $index === 0 ? 0.45 : 0.2];
    $expectedEffects = $index === 0 ? [['Type' => 'Volume', 'Gain' => 0], $transition] : [$transition];
    checkTransitionResult(true, $mainTrack['MainTrack']);
    checkTransitionResult($expectedEffects, $mainClips[1]['Effects']);
    checkTransitionResult('cover.jpg', $mainClips[0]['MediaURL']);
    checkTransitionResult([], $mainClips[0]['Effects'] ?? []);
    checkTransitionResult([], $mainClips[2]['Effects'] ?? []);
    checkTransitionResult([], $mainClips[3]['Effects'] ?? []);
    checkTransitionResult([], $mainClips[4]['Effects'] ?? []);
    checkTransitionResult($index === 0 ? 12.05 : 9.9, $plan['duration']);
    checkTransitionResult([
        'Type' => 'VFX', 'SubType' => 'white',
        'TimelineIn' => $index === 0 ? 5.9 : 3.75,
        'TimelineOut' => $index === 0 ? 6.2 : 4.05,
    ], $timeline['EffectTracks'][0]['EffectTrackItems'][0]);
}

$randomPolicy = Timeline::normalizeTransitionPolicy([
    'random' => true, 'selected' => ['dissolve'], 'overrides' => [null],
], 1);
checkTransitionResult([
    'diag', 'swirl', 'grid', 'vbow', 'hbow', 'zoom-out', 'lblur', 'drop', 'blind', 'flash', 'dissolve', 'push',
], $randomPolicy['candidates']);

$policy = Timeline::normalizeTransitionPolicy([
    'random' => false, 'selected' => ['diag', 'drop', 'dissolve', 'diag'], 'overrides' => [null, 'none', null],
], 3);
checkTransitionResult(['diag', 'drop', 'dissolve'], $policy['candidates']);
$policy['seed'] = str_repeat('a', 32);
$groupConfig = ['transition_policy' => $policy];
$reloadedConfig = json_decode(json_encode($groupConfig, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$batchShots = SmartEditGroupService::normalizeShots([
    ['videos' => [['uri' => 'a1', 'out' => 3], ['uri' => 'a2', 'out' => 3], ['uri' => 'a3', 'out' => 3]]],
    ['videos' => [['uri' => 'b1', 'out' => 3], ['uri' => 'b2', 'out' => 3]]],
    ['videos' => [['uri' => 'c', 'out' => 3]]],
    ['videos' => [['uri' => 'd', 'out' => 3]]],
]);
$draws = [];
foreach (SmartEditGroupService::expandShots($batchShots) as $index => $combo) {
    $comboIndex = $index + 1;
    $keys = SmartEditService::sharedForCombo($groupConfig, $comboIndex)['transitions'];
    checkTransitionResult($keys, SmartEditService::sharedForCombo($reloadedConfig, $comboIndex)['transitions']);
    checkTransitionResult('none', $keys[1]);
    checkTransitionResult([], array_diff([$keys[0], $keys[2]], $policy['candidates']));
    $items = array_map(static fn(array $clip): array => ['media_url' => $clip['uri']] + $clip, $combo);
    $plan = Timeline::planTransitions($items, $keys, false);
    checkTransitionResult([0, 2], array_keys($plan['clip_effects']));
    $draws[] = [$keys[0], $keys[2]];
}
checkTransitionResult(6, count($draws));
checkTransitionResult(true, count(array_unique(array_column($draws, 0))) > 1);
checkTransitionResult(true, array_column($draws, 0) !== array_column($draws, 1));

$noPool = Timeline::normalizeTransitionPolicy([
    'random' => false, 'selected' => [], 'overrides' => [null, 'flash', 'none'],
], 3);
checkTransitionResult(['none', 'flash', 'none'], Timeline::transitionsForCombo($noPool, 1));

$items = [['media_url' => 'a', 'out' => 3], ['media_url' => 'b', 'out' => 0.6], ['media_url' => 'c', 'out' => 3]];
$plan = Timeline::planTransitions($items, ['push', 'slide'], false);
checkTransitionResult([0.2, 0.2], array_column($plan['clip_effects'], 'Duration'));
checkTransitionResult(6.2, $plan['duration']);

$tinyItems = [['media_url' => 'a', 'out' => 3], ['media_url' => 'b', 'out' => 0.1]];
$plan = Timeline::planTransitions($tinyItems, ['push'], false);
checkTransitionResult([], $plan['clip_effects']);
checkTransitionResult(3.1, $plan['duration']);

$oldTimeline = Timeline::buildMerge($items);
foreach ([[], ['none', 'none']] as $keys) {
    $plan = Timeline::planTransitions($items, $keys, false);
    checkTransitionResult(6.6, $plan['duration']);
    checkTransitionResult($oldTimeline, Timeline::buildMerge($items, transitionPlan: $plan));
}
checkTransitionResult(0.0, Timeline::planTransitions([['media_url' => 'old']], [], false)['duration']);

foreach ([['none'], ['unsupported', 'none'], [1 => 'none', 2 => 'none'], ['none', ['fade']]] as $invalid) {
    try {
        Timeline::normalizeTransitions($invalid, 2);
    } catch (Exception $e) {
        continue;
    }
    throw new RuntimeException('Invalid transition list was accepted');
}

foreach ([
    ['random' => 'true', 'selected' => [], 'overrides' => [null, null]],
    ['random' => false, 'selected' => ['unsupported'], 'overrides' => [null, null]],
    ['random' => false, 'selected' => [], 'overrides' => [null]],
    ['random' => false, 'selected' => [], 'overrides' => [null, ['diag']]],
] as $invalidPolicy) {
    try {
        Timeline::normalizeTransitionPolicy($invalidPolicy, 2);
    } catch (Exception $e) {
        continue;
    }
    throw new RuntimeException('Invalid transition policy was accepted');
}

echo "PASS smart edit transition draws, retries, timeline, and backward compatibility\n";
