<?php

declare(strict_types=1);

namespace app\common\service\user_task;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class UserTaskSchedule
{
    private const DEFAULT_TIMEZONE = 'Asia/Shanghai';
    private const MAX_TIMESTAMP = 4294967295;
    private const TYPES = ['once', 'interval', 'daily', 'weekly', 'monthly'];

    private function __construct(
        public readonly string $type,
        public readonly int $startAt,
        public readonly ?int $endAt,
        public readonly DateTimeZone $timezone,
        public readonly int $interval,
        public readonly array $config,
    ) {
    }

    public static function fromInput(array $input): self
    {
        $zone = $input['timezone'] ?? self::DEFAULT_TIMEZONE;
        if (!is_string($zone) || !in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('请选择有效时区');
        }
        $timezone = new DateTimeZone($zone);
        $type = $input['schedule_type'] ?? null;
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('请选择有效的执行频率');
        }
        $config = $input['schedule_config'] ?? [];
        if (!is_array($config)) {
            throw new InvalidArgumentException('计划规则无效');
        }

        $interval = $type === 'interval'
            ? self::integer($config['interval_seconds'] ?? null, 1, self::MAX_TIMESTAMP, '执行间隔必须是正整数秒')
            : 0;
        $startAt = self::startFromDate($input, $timezone, $type);
        $endAt = self::endFromDate($input['end_date'] ?? '', $timezone);
        if ($endAt !== null && $endAt <= $startAt) {
            throw new InvalidArgumentException('结束时间必须晚于开始时间');
        }
        return new self($type, $startAt, $endAt, $timezone, $interval, self::normalizeConfig($type, $config));
    }

    public static function fromRow(array $row): self
    {
        return new self(
            $row['schedule_type'],
            (int)$row['start_at'],
            isset($row['end_at']) ? (int)$row['end_at'] : null,
            new DateTimeZone($row['timezone']),
            (int)$row['interval_seconds'],
            json_decode($row['schedule_config'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function toRow(): array
    {
        return [
            'schedule_type' => $this->type,
            'schedule_config' => json_encode((object)$this->config, JSON_THROW_ON_ERROR),
            'timezone' => $this->timezone->getName(),
            'start_at' => $this->startAt,
            'end_at' => $this->endAt,
            'interval_seconds' => $this->interval,
        ];
    }

    /** 有效期包含开始时间，结束时间为首个不能执行的时刻。 */
    public function hasEnded(int $timestamp): bool
    {
        return $this->endAt !== null && $timestamp >= $this->endAt;
    }

    /** 返回不早于给定时间的第一个有效轮次，预览和真实调度共用。 */
    public function next(int $from): ?int
    {
        $from = max($from, $this->startAt);
        if ($this->hasEnded($from)) {
            return null;
        }

        $next = match ($this->type) {
            'once' => $from === $this->startAt ? $from : null,
            'interval' => $this->startAt + intdiv($from - $this->startAt + $this->interval - 1, $this->interval) * $this->interval,
            default => $this->calendarOccurrence($from, 1),
        };
        return $next !== null && !$this->hasEnded($next) ? $next : null;
    }

    /** 扫描落后时只登记最近一轮，避免恢复后集中补跑。 */
    public function latestDue(int $firstDue, int $now): int
    {
        return match ($this->type) {
            'once' => $firstDue,
            'interval' => $firstDue + intdiv($now - $firstDue, $this->interval) * $this->interval,
            default => max($firstDue, $this->calendarOccurrence($now, -1) ?? $firstDue),
        };
    }

    private function calendarOccurrence(int $from, int $direction): ?int
    {
        // 日期单独按日历移动，每一轮再用计划时区解释本地时刻。
        $local = (new DateTimeImmutable('@' . $from))->setTimezone($this->timezone);
        $date = self::date($local->format('Y-m-d'));
        $step = $direction > 0 ? '+1 day' : '-1 day';
        for ($i = 0; $i < 370; $i++, $date = $date->modify($step)) {
            if (!$this->matchesDate($date)) {
                continue;
            }

            $text = $date->format('Y-m-d') . ' ' . $this->config['time'];
            $occurrence = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $text, $this->timezone);
            // 夏令时跳过的本地时间没有对应轮次。
            if (!$occurrence || $occurrence->format('Y-m-d H:i') !== $text) {
                continue;
            }
            $timestamp = $occurrence->getTimestamp();
            if (($direction > 0 && $timestamp >= $from) || ($direction < 0 && $timestamp <= $from)) {
                return $timestamp;
            }
        }
        return null;
    }

    private function matchesDate(DateTimeImmutable $date): bool
    {
        return match ($this->type) {
            'weekly' => in_array((int)$date->format('N'), $this->config['weekdays'], true),
            'monthly' => (int)$date->format('j') === (
                $this->config['month_day'] === 'last' ? (int)$date->format('t') : $this->config['month_day']
            ),
            default => true,
        };
    }

    private static function normalizeConfig(string $type, array $config): array
    {
        if ($type === 'once' || $type === 'interval') {
            return [];
        }

        $result = ['time' => self::clockTime($config['time'] ?? null)];
        if ($type === 'weekly') {
            $weekdays = $config['weekdays'] ?? null;
            if (!is_array($weekdays) || !array_is_list($weekdays) || $weekdays === [] || count($weekdays) > 7) {
                throw new InvalidArgumentException('请选择至少一个星期');
            }
            $days = array_map(static fn (mixed $day): int => self::integer($day, 1, 7, '星期无效'), $weekdays);
            $days = array_values(array_unique($days));
            sort($days);
            $result['weekdays'] = $days;
        } elseif ($type === 'monthly') {
            $day = $config['month_day'] ?? null;
            $result['month_day'] = $day === 'last' ? 'last' : self::integer($day, 1, 31, '请选择每月执行日期');
        }
        return $result;
    }

    private static function startFromDate(array $input, DateTimeZone $timezone, string $type): int
    {
        $day = self::date($input['start_date'] ?? null)->format('Y-m-d');
        $isTimed = $type === 'once' || $type === 'interval';
        $text = $day . ' ' . ($isTimed ? self::clockTime($input['start_time'] ?? null) : '00:00');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $text, $timezone);
        if (!$start || ($isTimed && $start->format('Y-m-d H:i') !== $text)) {
            throw new InvalidArgumentException('所选开始时间在该时区无效');
        }
        return self::integer($start->getTimestamp(), 1, self::MAX_TIMESTAMP, '开始日期超出范围');
    }

    private static function endFromDate(mixed $endDate, DateTimeZone $timezone): ?int
    {
        if ($endDate === '') {
            return null;
        }

        // 用户选择的结束日期包含全天，存成该时区次日的开始时间。
        $nextDay = self::date($endDate)->modify('+1 day')->format('Y-m-d');
        $end = new DateTimeImmutable($nextDay . ' 00:00:00', $timezone);
        return self::integer($end->getTimestamp(), 1, self::MAX_TIMESTAMP, '结束日期超出范围');
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new InvalidArgumentException('请选择有效日期');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('请选择有效日期');
        }
        return $date;
    }

    private static function clockTime(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value)) {
            throw new InvalidArgumentException('请选择有效的执行时间');
        }
        return $value;
    }

    private static function integer(mixed $value, int $min, int $max, string $error): int
    {
        $number = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]])
            : false;
        if ($number === false) {
            throw new InvalidArgumentException($error);
        }
        return $number;
    }
}
