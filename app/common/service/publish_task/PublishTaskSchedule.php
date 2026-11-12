<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PublishTaskSchedule
{
    public const TIMEZONE = 'Asia/Shanghai';
    // Leave a day for consumption and redelivery within the queue's seven-day retention.
    public const MAX_DELAY_SECONDS = 6 * 86400;

    public static function normalize(array $input): array
    {
        $mode = $input['push_mode'] ?? null;
        if ($mode === 'immediate') {
            return ['push_mode' => $mode, 'schedule_type' => null, 'push_time' => null,
                'weekday' => null, 'start_date' => null, 'end_date' => null];
        }
        if ($mode !== 'scheduled') {
            throw new InvalidArgumentException('请选择立即推送或定时推送');
        }

        $type = $input['schedule_type'] ?? null;
        if (!in_array($type, ['daily', 'weekly', 'date_range'], true)) {
            throw new InvalidArgumentException('请选择每天、每周或自定义日期范围');
        }
        $time = $input['push_time'] ?? null;
        if (!is_string($time) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
            throw new InvalidArgumentException('推送时间格式必须为 HH:mm');
        }

        $weekday = $start = $end = null;
        if ($type === 'weekly') {
            $day = $input['weekday'] ?? null;
            if ((!is_int($day) && !is_string($day)) || !preg_match('/^[1-7]$/D', (string)$day)) {
                throw new InvalidArgumentException('请选择星期，1 为周一，7 为周日');
            }
            $weekday = (int)$day;
        } elseif ($type === 'date_range') {
            $start = self::date($input['start_date'] ?? null);
            $end = self::date($input['end_date'] ?? null);
            if ($end < $start || $end >= $start->modify('+30 days')) {
                throw new InvalidArgumentException('自定义日期范围必须为 1 至 30 天（含首尾）');
            }
        }

        return ['push_mode' => $mode, 'schedule_type' => $type, 'push_time' => $time,
            'weekday' => $weekday, 'start_date' => $start?->format('Y-m-d'), 'end_date' => $end?->format('Y-m-d')];
    }

    public static function first(array $rule, int $now): ?int
    {
        return $rule['push_mode'] === 'immediate' ? $now : self::next($rule, $now);
    }

    /** Return the first occurrence at or after $from, or null if there is none. */
    public static function next(array $rule, int $from): ?int
    {
        if ($rule['push_mode'] === 'immediate' || self::hasEnded($rule, $from)) {
            return null;
        }

        $local = self::local($from);
        if ($rule['start_date'] !== null && $local->format('Y-m-d') < $rule['start_date']) {
            $local = self::date($rule['start_date']);
        }
        [$hour, $minute] = array_map('intval', explode(':', $rule['push_time']));
        $candidate = $local->setTime($hour, $minute);
        if ($candidate->getTimestamp() < $from) {
            $candidate = $candidate->modify('+1 day');
        }
        if ($rule['schedule_type'] === 'weekly') {
            $days = ((int)$rule['weekday'] - (int)$candidate->format('N') + 7) % 7;
            $candidate = $candidate->modify('+' . $days . ' days');
        }
        $timestamp = $candidate->getTimestamp();
        return self::hasEnded($rule, $timestamp) ? null : $timestamp;
    }

    public static function hasEnded(array $rule, int $now): bool
    {
        return $rule['end_date'] !== null && self::local($now)->format('Y-m-d') > $rule['end_date'];
    }

    public static function delay(int $scheduledAt, int $now): int
    {
        return min(self::MAX_DELAY_SECONDS, max(0, $scheduledAt - $now));
    }

    private static function local(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            throw new InvalidArgumentException('日期格式必须为 YYYY-MM-DD');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::TIMEZONE));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('请选择有效日期');
        }
        return $date;
    }
}
