<?php

declare(strict_types=1);

namespace app\common\service\workbench;

use app\common\enum\user\AccountLogEnum;
use app\common\model\geo\GeoOrder;
use app\common\model\merchant\PublishTask;
use app\common\model\smart_edit\SmartEditTask;
use app\common\model\user\User;
use app\common\model\user\UserAccountLog;
use think\facade\Db;
use think\facade\Log;
use Throwable;

/** Read-only statistics for the signed-in PC user. */
final class UserOverview
{
    private const DAY = 86400;
    private const CONSUMPTION_DAYS = 15;
    private const TASK_SOURCES = [
        // Image and video engines already contain the tasks submitted through wrapper tools.
        'aigc_image' => 'image',
        'aigc_video' => 'video',
        'aigc_digital_human' => 'avatar',
        'aigc_pic_lipsync' => 'avatar',
        'image_human' => 'avatar',
        'smart_edit' => 'video',
        'smart_clip' => 'video',
        'aigc_micro_video' => 'video',
    ];

    public static function build(int $tenantId, int $userId, ?int $now = null): array
    {
        $owner = ['tenant_id' => $tenantId, 'user_id' => $userId];
        $range = OperationsRange::resolve(['range' => '7d'], $now);

        return [
            'range' => array_intersect_key($range, array_flip(['start_date', 'end_date', 'labels'])),
            'balance' => self::read('balance', static function () use ($tenantId, $userId) {
                $balance = User::where(['tenant_id' => $tenantId, 'id' => $userId])->value('user_money');
                return $balance === null ? null : (float)$balance;
            }),
            'consumption' => self::read('consumption', static fn() => self::consumption($owner, $range)),
            'generation' => self::generation($owner, $range),
            'promotion' => self::read('promotion', static fn() => self::promotion($owner, $range)),
            'team' => self::read('team', static fn() => self::team($owner)),
        ];
    }

    private static function consumption(array $owner, array $range): array
    {
        $from = $range['start_at'] - (self::CONSUMPTION_DAYS - count($range['labels'])) * self::DAY;
        $day = self::DAY;
        $rows = UserAccountLog::where($owner)
            ->where('action', AccountLogEnum::DEC)
            ->whereIn('change_type', AccountLogEnum::getUserMoneyChangeType())
            ->where('create_time', '>=', $from)
            ->where('create_time', '<', $range['end_at'])
            ->fieldRaw("FLOOR((create_time - {$from}) / {$day}) AS bucket, SUM(change_amount) AS amount")
            ->group('bucket')
            ->select();

        $daily = array_fill(0, self::CONSUMPTION_DAYS, 0);
        foreach ($rows as $row) {
            $daily[(int)$row['bucket']] = round((float)$row['amount'], 2);
        }
        return ['total' => round(array_sum($daily), 2), 'daily' => $daily];
    }

    private static function generation(array $owner, array $range): ?array
    {
        $days = count($range['labels']);
        $empty = array_fill(0, $days, 0);
        $result = [
            'total' => 0, 'previous' => 0, 'completed' => 0,
            'films' => 0, 'previous_films' => 0,
            'daily' => $empty, 'avatar_daily' => $empty, 'video_daily' => $empty,
            'partial' => false,
        ];
        $loaded = 0;

        foreach (self::TASK_SOURCES as $app => $kind) {
            $rows = self::read($app, static fn() => self::taskDays($app, $owner, $range));
            if ($rows === null) {
                $result['partial'] = true;
                continue;
            }
            $loaded++;
            foreach ($rows as $row) {
                $bucket = (int)$row['bucket'];
                $count = (int)$row['total'];
                $completed = (int)$row['completed'];
                if ($bucket < $days) {
                    $result['previous'] += $count;
                    if ($kind !== 'image') $result['previous_films'] += $completed;
                    continue;
                }

                $index = $bucket - $days;
                $result['daily'][$index] += $count;
                $result['total'] += $count;
                $result['completed'] += $completed;
                if ($kind !== 'image') {
                    $result['films'] += $completed;
                    $result[$kind . '_daily'][$index] += $count;
                }
            }
        }

        return $loaded > 0 ? $result : null;
    }

    private static function taskDays(string $app, array $owner, array $range): iterable
    {
        // Smart edit uses the shared model's tenant table and soft-delete policy.
        $query = $app === 'smart_edit'
            ? SmartEditTask::where($owner)
            : Db::name($app . '_task')->where($owner)->where('delete_time', 0);
        if ($app === 'aigc_micro_video') {
            $query->whereNotIn('status', ['prompt_draft', 'prompt_ready']);
        }

        $from = $range['previous_start_at'];
        $day = self::DAY;
        return self::periods($query, $range)
            ->fieldRaw("FLOOR((create_time - {$from}) / {$day}) AS bucket, COUNT(*) AS total, SUM(status = 'success') AS completed")
            ->group('bucket')
            ->select();
    }

    private static function promotion(array $owner, array $range): array
    {
        $start = $range['start_at'];
        $rows = self::periods(GeoOrder::where($owner), $range)
            ->fieldRaw("create_time >= {$start} AS current_period, status, COUNT(*) AS total")
            ->group('current_period,status')
            ->select();
        $result = ['total' => 0, 'published' => 0, 'pending' => 0, 'failed' => 0, 'other' => 0, 'previous_published' => 0];
        foreach ($rows as $row) {
            $key = match ((int)$row['status']) {
                GeoOrder::STATUS_PUBLISHED, GeoOrder::STATUS_DONE => 'published',
                GeoOrder::STATUS_LOCAL, GeoOrder::STATUS_PENDING, GeoOrder::STATUS_ARRANGED, GeoOrder::STATUS_FEEDBACK => 'pending',
                GeoOrder::STATUS_REJECTED, GeoOrder::STATUS_MISSING => 'failed',
                default => 'other',
            };
            $count = (int)$row['total'];
            if ((int)$row['current_period'] === 1) {
                $result[$key] += $count;
                $result['total'] += $count;
            } elseif ($key === 'published') {
                $result['previous_published'] += $count;
            }
        }
        return $result;
    }

    private static function team(array $owner): array
    {
        $query = PublishTask::where($owner)->where('status', 'scheduled');
        $count = (clone $query)->count();
        $lists = $query->field('id,content,next_run_at')->order('next_run_at asc,id desc')
            ->limit(5)->select()->toArray();
        foreach ($lists as &$plan) {
            $plan['id'] = (int)$plan['id'];
            $plan['next_run_at'] = isset($plan['next_run_at']) ? (int)$plan['next_run_at'] : null;
        }
        return ['count' => (int)$count, 'lists' => $lists];
    }

    private static function periods($query, array $range)
    {
        return $query
            ->where('create_time', '>=', $range['previous_start_at'])
            ->where('create_time', '<', $range['end_at'])
            ->where(static function ($periods) use ($range) {
                // Compare equal elapsed time even when today's day is still in progress.
                $periods->where('create_time', '<', $range['previous_end_at'])
                    ->whereOr('create_time', '>=', $range['start_at']);
            });
    }

    private static function read(string $source, callable $load): mixed
    {
        try {
            return $load();
        } catch (Throwable $error) {
            Log::warning('User workbench source ' . $source . ' unavailable: ' . get_class($error));
            return null;
        }
    }
}
