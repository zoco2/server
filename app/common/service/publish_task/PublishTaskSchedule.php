<?php

declare(strict_types=1);

namespace app\common\service\publish_task;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** 校验任务的推送规则，并按北京时间计算执行时间。 */
final class PublishTaskSchedule
{
    public const TIMEZONE = 'Asia/Shanghai';

    /**
     * 将请求参数转换为可存储的调度规则，未使用的字段统一置空。
     *
     * @throws InvalidArgumentException 推送模式、时间或日期范围无效。
     */
    public static function normalize(array $input): array
    {
        $mode = $input['push_mode'] ?? null;
        if ($mode === 'immediate') {
            // 立即推送只执行一次，无需设置定时字段。
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
            // 使用 ISO 星期序号，与计算候选日期时的 format('N') 保持一致。
            $day = $input['weekday'] ?? null;
            if ((!is_int($day) && !is_string($day)) || !preg_match('/^[1-7]$/D', (string)$day)) {
                throw new InvalidArgumentException('请选择星期，1 为周一，7 为周日');
            }
            $weekday = (int)$day;
        } elseif ($type === 'date_range') {
            $start = self::date($input['start_date'] ?? null);
            $end = self::date($input['end_date'] ?? null);
            // 起止日期都计入范围，相差 29 天时刚好包含 30 个自然日。
            if ($end < $start || $end >= $start->modify('+30 days')) {
                throw new InvalidArgumentException('自定义日期范围必须为 1 至 30 天（含首尾）');
            }
        }

        return ['push_mode' => $mode, 'schedule_type' => $type, 'push_time' => $time,
            'weekday' => $weekday, 'start_date' => $start?->format('Y-m-d'), 'end_date' => $end?->format('Y-m-d')];
    }

    /** 计算首轮执行的 Unix 时间戳，日期范围内没有可执行时间时返回 null。 */
    public static function first(array $rule, int $now): ?int
    {
        return $rule['push_mode'] === 'immediate' ? $now : self::next($rule, $now);
    }

    /** 返回不早于 $from 的最近执行时间戳，范围内没有后续执行时间时返回 null。 */
    public static function next(array $rule, int $from): ?int
    {
        if ($rule['push_mode'] === 'immediate' || self::hasEnded($rule, $from)) {
            return null;
        }

        $local = self::local($from);
        if ($rule['start_date'] !== null && $local->format('Y-m-d') < $rule['start_date']) {
            // 尚未进入日期范围时，从开始日计算首个推送时间。
            $local = self::date($rule['start_date']);
        }
        [$hour, $minute] = array_map('intval', explode(':', $rule['push_time']));
        $candidate = $local->setTime($hour, $minute);
        if ($candidate->getTimestamp() < $from) {
            // 当天的推送时间已过则从次日继续找，恰好到点时仍执行当天这轮。
            $candidate = $candidate->modify('+1 day');
        }
        if ($rule['schedule_type'] === 'weekly') {
            // 从候选日向后找到最近的指定星期，允许跨周。
            $days = ((int)$rule['weekday'] - (int)$candidate->format('N') + 7) % 7;
            $candidate = $candidate->modify('+' . $days . ' days');
        }
        $timestamp = $candidate->getTimestamp();
        return self::hasEnded($rule, $timestamp) ? null : $timestamp;
    }

    /** 结束日期包含当天，直到北京时间次日零点才判定任务过期。 */
    public static function hasEnded(array $rule, int $now): bool
    {
        return $rule['end_date'] !== null && self::local($now)->format('Y-m-d') > $rule['end_date'];
    }

    /** 按北京时间解释时间戳，避免受 PHP 默认时区影响。 */
    private static function local(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    /** 将有效的 YYYY-MM-DD 解析为北京时间当天零点。 */
    private static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            throw new InvalidArgumentException('日期格式必须为 YYYY-MM-DD');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::TIMEZONE));
        // PHP 可能自动滚动无效日期，重新格式化后应与输入完全一致。
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('请选择有效日期');
        }
        return $date;
    }
}
