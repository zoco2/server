<?php

declare(strict_types=1);

use app\common\service\publish_task\PublishTaskSchedule as Schedule;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// 将进程默认时区设为 UTC，验证任务仍按北京时间计算。
date_default_timezone_set('UTC');

/** 将北京时间的用例时间转换为 Unix 时间戳。 */
function at(string $date): int
{
    return (new DateTimeImmutable($date, new DateTimeZone('Asia/Shanghai')))->getTimestamp();
}

/** 对执行结果做严格比较，失败时显示用例名和实际值。 */
function same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

// 立即推送使用当前时间作为首轮执行时间，没有下一轮。
$today = at('2026-11-12 20:13:00');
$tomorrow = at('2026-11-13 20:13:00');
$immediate = Schedule::normalize(['push_mode' => 'immediate']);
same($today, Schedule::first($immediate, $today), 'immediate first occurrence');
same(null, Schedule::next($immediate, $today + 1), 'immediate is a one-shot task');

// 每日任务恰好到点时执行当天这轮，过点后安排次日执行。
$clock = ['push_mode' => 'scheduled', 'push_time' => '20:13'];
$daily = Schedule::normalize($clock + ['schedule_type' => 'daily']);
same($today, Schedule::first($daily, $today), 'at the exact minute in Shanghai');
same($tomorrow, Schedule::first($daily, $today + 1), 'past today moves to tomorrow');

// 每周任务跨周时仍落在指定星期，较长的等待拆成多次延时投递。
$weekly = Schedule::normalize($clock + ['schedule_type' => 'weekly', 'weekday' => 4]);
same($today, Schedule::first($weekly, at('2026-11-08 00:00:00')), 'weekly rolls forward to Thursday');
$nextWeek = at('2026-11-19 20:13:00');
same($nextWeek, Schedule::next($weekly, $today + 1), 'after Thursday goes to next Thursday');
$relayAt = Schedule::wakeAt($nextWeek, $today);
same(at('2026-11-18 20:13:00'), $relayAt, 'long wait gets a relay');
same($nextWeek, Schedule::first($weekly, $relayAt), 'relay time resolves to the original weekly occurrence');
same($nextWeek, Schedule::wakeAt($nextWeek, $relayAt), 'the next message targets the weekly occurrence');
same($today, Schedule::wakeAt($today, $today + 1), 'a late enqueue retains the original occurrence time');

// 日期范围包括首尾两天，末轮消息过了结束日期的午夜才失效。
$range = Schedule::normalize($clock + ['schedule_type' => 'date_range', 'start_date' => '2026-11-12', 'end_date' => '2026-11-13']);
same($today, Schedule::first($range, at('2026-10-01 21:00:00')), 'future range starts on its first day');
same($tomorrow, Schedule::next($range, $today + 1), 'range includes its last day');
same(null, Schedule::next($range, $tomorrow + 1), 'no slot after the last scheduled time');
same(false, Schedule::hasEnded($range, at('2026-11-13 23:59:59')), 'a late wake on the last day can still run');
same(true, Schedule::hasEnded($range, at('2026-11-14 00:00:00')), 'after midnight the last slot must not run');
same(null, Schedule::first($range, at('2026-11-14 00:00:00')), 'expired range has no first slot');

// 校验时刻、星期、自然日以及日期范围的顺序和长度。
foreach ([
    ['push_time' => '24:00', 'schedule_type' => 'daily'],
    ['schedule_type' => 'weekly', 'weekday' => 0],
    ['schedule_type' => 'date_range', 'start_date' => '2026-02-29', 'end_date' => '2026-03-01'],
    ['schedule_type' => 'date_range', 'start_date' => '2026-11-13', 'end_date' => '2026-11-12'],
    ['schedule_type' => 'date_range', 'start_date' => '2026-11-01', 'end_date' => '2026-12-01'],
] as $input) {
    try {
        Schedule::normalize($input + $clock);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('Invalid schedule was accepted: ' . json_encode($input));
}

echo "PASS publish task schedule, range expiry and MNS delay relay\n";
