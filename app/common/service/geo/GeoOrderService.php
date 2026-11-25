<?php

namespace app\common\service\geo;

use app\common\enum\user\AccountLogEnum;
use app\common\exception\GeoTokenExpiredException;
use app\common\model\geo\GeoArticle;
use app\common\model\geo\GeoMedia;
use app\common\model\geo\GeoOrder;
use app\common\model\geo\GeoOrderLog;
use app\common\model\user\UserAccountLog;
use app\common\service\point\PointService;
use Exception;
use InvalidArgumentException;
use think\facade\Db;
use UnexpectedValueException;

/**
 * GEO 投稿：推送海讯社 / 回查状态 / 确认发布 / 提交修改意见。
 *
 * 海讯社没有回调，状态只能靠 geo:order-sync 轮询 queryON。
 * 建单 price 必须与海讯最新价完全相等，否则直接 priceError 拒稿，
 * 所以本地记录的 cost_price 只用于比对，推送前会先刷新一次资源状态。
 */
class GeoOrderService
{
    /** order_no 前缀，海讯要求接入方唯一订单号 */
    public const ORDER_PREFIX = 'GEO';

    /** 超过这个秒数没有查询过的订单会被轮询 */
    public const QUERY_INTERVAL = 300;

    /**
     * 创建投稿单并立即推送。失败时保留一条本地订单，方便后台重试。
     *
     * @param int $userId 前台用户 ID
     * @param array<string, mixed> $params article_id / media_id / price_type / expected_points / remark / origin
     * @return array<string, mixed>
     */
    public static function create(int $userId, array $params): array
    {
        $articleId = (int)($params['article_id'] ?? 0);
        $mediaId = (int)($params['media_id'] ?? 0);
        $priceType = max(1, (int)($params['price_type'] ?? 1));

        $article = GeoArticle::where('id', $articleId)->findOrEmpty();
        if ($article->isEmpty() || (int)$article['user_id'] !== $userId) {
            throw new InvalidArgumentException('稿件不存在');
        }
        if (trim((string)$article['content']) === '') {
            throw new InvalidArgumentException('稿件正文为空，不能投稿');
        }
        if (trim((string)$article['preview_url']) === '') {
            throw new InvalidArgumentException('请先保存稿件再投稿');
        }
        $media = GeoMedia::where('id', $mediaId)->findOrEmpty();
        if ($media->isEmpty()) {
            throw new InvalidArgumentException('媒体资源不存在');
        }
        if ((int)$media['flag'] !== 1 || (int)$media['deleted'] !== 1) {
            throw new InvalidArgumentException('该媒体已下架或已删除，请重新选择');
        }

        // 多价格类别：短视频/微信/微博必须选对类别，否则海讯按错误价格拒稿。
        [$costPrice, $salePrice] = self::resolvePrice($media, $priceType);
        if (array_key_exists('expected_points', $params)
            && filter_var($params['expected_points'], FILTER_VALIDATE_INT) !== self::salePoints($salePrice)) {
            throw new InvalidArgumentException('媒体报价已变动，请重新核对发稿算力');
        }

        $orderData = [
            'tenant_id' => (int)$article['tenant_id'],
            'user_id' => $userId,
            'order_no' => self::newOrderNo(),
            'article_id' => $articleId,
            'media_id' => $mediaId,
            'sourceid' => (string)$media['sourceid'],
            'resource_type' => (int)$media['resource_type'],
            'media_name' => (string)$media['name'],
            'price_type' => $priceType,
            'cost_price' => $costPrice,
            'sale_price' => $salePrice,
            'title' => (string)$article['title'],
            'content_url' => (string)$article['preview_url'],
            'remark' => mb_substr(trim((string)($params['remark'] ?? '')), 0, 500),
            'origin' => mb_substr(trim((string)($params['origin'] ?? '')), 0, 255),
            'status' => GeoOrder::STATUS_LOCAL,
            'remote_flag' => -1,
            'create_time' => time(),
            'update_time' => time(),
        ];
        // 建单和预占一起提交，余额不足时不会推送订单。
        $order = Db::transaction(static function () use ($orderData): GeoOrder {
            $order = GeoOrder::create($orderData);
            self::reserve($order);
            GeoOrderLog::record($order, GeoOrder::STATUS_LOCAL, GeoOrder::STATUS_LOCAL, 'create', '创建投稿单');
            return $order;
        });

        // 推送失败不抛给用户：订单已落库，后台可重试。
        $order = self::push($order);
        return self::detail((int)$order['id'], $userId);
    }

    /**
     * 提交一张本地投稿单。
     *
     * @param GeoOrder $order 投稿单
     * @return GeoOrder 刷新后的订单
     */
    public static function push(GeoOrder $order): GeoOrder
    {
        $status = (int)$order['status'];
        if (!in_array($status, [GeoOrder::STATUS_LOCAL, GeoOrder::STATUS_REJECTED], true)) {
            throw new Exception('只有待推送或被拒稿的订单可以重新推送');
        }
        if ($status === GeoOrder::STATUS_REJECTED) {
            // 重推先提交本次预占。即使推送进程中断，定时任务也能继续推同一个订单。
            Db::transaction(static function () use ($order): void {
                $locked = self::lockOrder($order);
                if ((int)$locked['status'] !== GeoOrder::STATUS_REJECTED
                    || (int)$locked['push_attempts'] !== (int)$order['push_attempts']
                    || !(bool)$locked['retryable']) {
                    throw new Exception('订单状态已变化或不可重推，请刷新');
                }
                if ((int)$locked['power_log_id'] > 0) {
                    self::reserve($locked);
                }
                $locked->save(['status' => GeoOrder::STATUS_LOCAL, 'remote_flag' => -1, 'retryable' => 0]);
            });
        }
        return Db::transaction(static function () use ($order): GeoOrder {
            $locked = self::lockOrder($order);
            return (int)$locked['status'] === GeoOrder::STATUS_LOCAL ? self::pushLocked($locked) : $locked;
        });
    }

    private static function pushLocked(GeoOrder $order): GeoOrder
    {
        $from = (int)$order['status'];
        if (trim((string)$order['content_url']) === '') {
            self::fail($order, '稿件缺少预览地址，无法投稿', false);
            return $order;
        }

        // 推送前刷新资源价与状态，避免海讯调价后 priceError。
        $media = GeoMedia::where('id', (int)$order['media_id'])->findOrEmpty();
        if ($media->isEmpty()) {
            self::fail($order, '媒体资源不存在', false);
            return $order;
        }
        if ((int)$media['flag'] !== 1 || (int)$media['deleted'] !== 1) {
            self::fail($order, '媒体已下架', false);
            return $order;
        }
        try {
            $costPrice = self::costOf($media, (int)$order['price_type']);
        } catch (InvalidArgumentException $e) {
            self::fail($order, $e->getMessage(), false);
            return $order;
        }
        if (abs($costPrice - (float)$order['cost_price']) > 0.005) {
            $order->save(['cost_price' => $costPrice, 'update_time' => time()]);
            GeoOrderLog::record($order, $from, $from, 'push', sprintf('媒体价格变动 %.2f -> %.2f', (float)$order['cost_price'], $costPrice));
        }

        $pushMessage = '投稿已提交';
        $lastQueryTime = 0;
        try {
            self::callWithToken(
                // createOrder 返回 void，包一层返回空数组以匹配调用器签名。
                static function (string $token) use ($order, $costPrice): array {
                    AigcGeoManuscriptService::createOrder($token, [
                        'ordernum' => (string)$order['order_no'],
                        'title' => (string)$order['title'],
                        'content' => (string)$order['content_url'],
                        'price' => $costPrice,
                        'sourceid' => (string)$order['sourceid'],
                        'priceType' => (int)$order['price_type'],
                        'remark' => (string)$order['remark'],
                        'origin' => (string)$order['origin'],
                    ]);
                    return [];
                }
            );
        } catch (Exception $e) {
            $message = $e->getMessage();
            // 可能已受理时保留预占，等待按原订单号回查。
            if (!($e instanceof UnexpectedValueException)
                && !str_contains($message, '订单已存在') && !str_contains($message, 'orderExist')) {
                self::fail($order, $message, self::isRetryableMessage($message));
                return $order;
            }
            $pushMessage = '提交结果待确认，将按原订单号查询';
            $lastQueryTime = time();
        }

        $order->save([
            'status' => GeoOrder::STATUS_PENDING,
            'remote_flag' => GeoOrder::STATUS_PENDING,
            'push_attempts' => (int)$order['push_attempts'] + 1,
            'last_push_time' => time(),
            'last_query_time' => $lastQueryTime,
            'fail_reason' => '',
            'retryable' => 0,
            'update_time' => time(),
        ]);
        GeoOrderLog::record($order, $from, GeoOrder::STATUS_PENDING, 'push', $pushMessage);
        return $order;
    }

    /**
     * 回查海讯订单状态并落库。
     *
     * @param GeoOrder $order 投稿单
     * @return GeoOrder
     */
    public static function sync(GeoOrder $order): GeoOrder
    {
        return Db::transaction(static fn (): GeoOrder => self::syncLocked(self::lockOrder($order)));
    }

    private static function syncLocked(GeoOrder $order): GeoOrder
    {
        if ((int)$order['status'] === GeoOrder::STATUS_LOCAL) {
            throw new Exception('订单尚未推送，无法查询');
        }
        // 已结算的终态以当前订单为准，避免重复回查改写账务状态。
        if ((int)$order['power_log_id'] > 0 && !in_array((int)$order['status'], GeoOrder::POLL_STATUS, true)) {
            return $order;
        }
        $result = self::callWithToken(
            static fn (string $token): array => AigcGeoManuscriptService::queryOrder($token, (string)$order['order_no'])
        );
        $from = (int)$order['status'];
        $to = (int)$result['flag'];
        $data = [
            'status' => $to,
            'remote_flag' => (int)$result['flag'],
            'publish_url' => mb_substr((string)$result['url'], 0, 500),
            'fail_reason' => mb_substr((string)$result['result'], 0, 500),
            'last_query_time' => time(),
            'update_time' => time(),
        ];
        if ($to === GeoOrder::STATUS_DONE) {
            $data['finish_time'] = time();
        }
        if (in_array($to, [GeoOrder::STATUS_REJECTED, GeoOrder::STATUS_MISSING], true)) {
            self::refund($order);
        }
        $order->save($data);
        if ($to !== $from) {
            GeoOrderLog::record($order, $from, $to, 'query', $result['status']);
        }
        return $order;
    }

    /**
     * 批量查询到期订单（定时任务用）。
     *
     * @param int $limit 单轮最多查询数量
     * @return array{pushed:int, queried:int, changed:int, failed:int}
     */
    public static function syncDue(int $limit = 100): array
    {
        $stat = ['pushed' => 0, 'queried' => 0, 'changed' => 0, 'failed' => 0];
        $now = time();

        // 待推送的先推上去（用户下单时推送失败的兜底）。
        $pending = GeoOrder::where('status', GeoOrder::STATUS_LOCAL)->limit($limit)->select();
        foreach ($pending as $order) {
            try {
                self::push($order);
                $stat['pushed']++;
            } catch (Exception) {
                $stat['failed']++;
            }
        }

        // 再轮询在途订单，按 last_query_time 节流。
        $orders = GeoOrder::whereIn('status', GeoOrder::POLL_STATUS)
            ->where('last_query_time', '<=', $now - self::QUERY_INTERVAL)
            ->order('last_query_time', 'asc')
            ->limit($limit)
            ->select();
        foreach ($orders as $order) {
            $before = (int)$order['status'];
            try {
                self::sync($order);
                $stat['queried']++;
                if ((int)$order['status'] !== $before) {
                    $stat['changed']++;
                }
            } catch (Exception) {
                $stat['failed']++;
            }
        }
        return $stat;
    }

    /**
     * 确认发布（flag=5）或提交修改意见（flag=4）。仅海讯状态为 3 时接受。
     *
     * @param GeoOrder $order 投稿单
     * @param int $flag 4 反馈 / 5 确认完成
     * @param string $feedback 反馈意见
     * @return GeoOrder
     */
    public static function process(GeoOrder $order, int $flag, string $feedback = ''): GeoOrder
    {
        return Db::transaction(static fn (): GeoOrder => self::processLocked(self::lockOrder($order), $flag, $feedback));
    }

    private static function processLocked(GeoOrder $order, int $flag, string $feedback): GeoOrder
    {
        if ((int)$order['remote_flag'] !== GeoOrder::STATUS_PUBLISHED) {
            throw new Exception('只有"发布成功待确认"的订单可以确认或反馈，当前状态：'
                . (GeoOrder::STATUS_TEXT[(int)$order['status']] ?? $order['status']));
        }
        self::callWithToken(
            // processOrder 返回 void，包一层返回空数组以匹配调用器签名。
            static function (string $token) use ($order, $flag, $feedback): array {
                AigcGeoManuscriptService::processOrder($token, (string)$order['order_no'], $flag, $feedback);
                return [];
            }
        );
        $from = (int)$order['status'];
        $to = $flag === 5 ? GeoOrder::STATUS_DONE : GeoOrder::STATUS_FEEDBACK;
        $order->save([
            'status' => $to,
            'remote_flag' => $to,
            'finish_time' => $flag === 5 ? time() : (int)$order['finish_time'],
            'update_time' => time(),
        ]);
        GeoOrderLog::record($order, $from, $to, $flag === 5 ? 'confirm' : 'feedback', $feedback);
        return $order;
    }

    /**
     * 订单详情（含状态日志）。用户侧需校验归属。
     *
     * @param int $id 订单 ID
     * @param int $userId 用户 ID，0 表示后台
     * @return array<string, mixed>
     */
    public static function detail(int $id, int $userId = 0): array
    {
        $order = GeoOrder::where('id', $id)->findOrEmpty();
        if ($order->isEmpty() || ($userId > 0 && (int)$order['user_id'] !== $userId)) {
            throw new Exception('投稿单不存在');
        }
        $data = $order->toArray();
        $data['charged_points'] = self::chargedPoints($data);
        $data['status_text'] = GeoOrder::STATUS_TEXT[$data['status']] ?? (string)$data['status'];
        $data['can_retry'] = in_array((int)$data['status'], [GeoOrder::STATUS_LOCAL, GeoOrder::STATUS_REJECTED], true)
            && ((int)$data['status'] === GeoOrder::STATUS_LOCAL || (bool)$data['retryable']);
        $data['can_confirm'] = (int)$data['remote_flag'] === GeoOrder::STATUS_PUBLISHED;
        $data['fail_reason'] = AigcGeoManuscriptService::publicMessage((string)$data['fail_reason']);
        $data['logs'] = GeoOrderLog::where('order_id', $id)
            ->order('id', 'asc')
            ->field('from_status,to_status,action,message,create_time')
            ->select()
            ->toArray();
        foreach ($data['logs'] as &$log) {
            if ($log['action'] !== 'feedback') {
                $log['message'] = AigcGeoManuscriptService::publicMessage((string)$log['message']);
            }
        }
        unset($log);
        return $data;
    }

    /**
     * 订单列表。
     *
     * @param array<string, mixed> $params 筛选条件
     * @param int $userId 用户 ID，0 表示后台
     * @return array{count:int, lists:array<int, array<string, mixed>>}
     */
    public static function lists(array $params, int $userId = 0): array
    {
        $query = GeoOrder::field('id,user_id,order_no,article_id,media_name,resource_type,price_type,'
            . 'cost_price,sale_price,title,status,remote_flag,publish_url,fail_reason,retryable,'
            . 'create_time,update_time,finish_time,power_log_id');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        foreach (['status', 'status_in' => 'in', 'resource_type'] as $key => $type) {
            $value = $params[$key] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            if ($key === 'status_in') {
                $query->whereIn('status', array_map('intval', (array)$value));
                continue;
            }
            $query->where($key, (int)$value);
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('title', '%' . $keyword . '%')
                    ->whereOr('media_name', 'like', '%' . $keyword . '%')
                    ->whereOr('order_no', 'like', '%' . $keyword . '%');
            });
        }
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $count = (int)(clone $query)->count();
        $rows = $query->order('id', 'desc')->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        foreach ($rows as &$row) {
            $row['charged_points'] = self::chargedPoints($row);
            $row['status_text'] = GeoOrder::STATUS_TEXT[$row['status']] ?? (string)$row['status'];
            $row['fail_reason'] = AigcGeoManuscriptService::publicMessage((string)$row['fail_reason']);
            $row['can_retry'] = in_array((int)$row['status'], [GeoOrder::STATUS_LOCAL, GeoOrder::STATUS_REJECTED], true)
                && ((int)$row['status'] === GeoOrder::STATUS_LOCAL || (bool)$row['retryable']);
            $row['can_confirm'] = (int)$row['remote_flag'] === GeoOrder::STATUS_PUBLISHED;
            // 内部列不下发给前端，前端只认 can_retry。
            unset($row['retryable']);
        }
        unset($row);
        return ['count' => $count, 'lists' => $rows];
    }

    /**
     * 资源价格类别 -> [成本价, 售价]。
     *
     * @param GeoMedia $media 媒体资源
     * @param int $priceType 价格类别
     * @return array{0:float, 1:float}
     */
    private static function resolvePrice(GeoMedia $media, int $priceType): array
    {
        $ratio = GeoMediaSyncService::markup();
        foreach ((array)$media->getAttr('price_type_json') as $item) {
            if ((int)($item['type'] ?? 0) === $priceType) {
                $cost = (float)($item['price'] ?? 0);
                return [$cost, GeoMediaSyncService::suggestSalePrice($cost, $ratio)];
            }
        }
        throw new InvalidArgumentException('该媒体没有价格类别 ' . $priceType . '，请重新选择');
    }

    /**
     * 取某价格类别的成本价。
     *
     * @param GeoMedia $media 媒体资源
     * @param int $priceType 价格类别
     */
    private static function costOf(GeoMedia $media, int $priceType): float
    {
        return self::resolvePrice($media, $priceType)[0];
    }

    private static function lockOrder(GeoOrder $order): GeoOrder
    {
        return GeoOrder::where(['id' => $order['id'], 'tenant_id' => $order['tenant_id']])->lock(true)->findOrFail();
    }

    private static function salePoints(float $salePrice): int
    {
        // 售价已包含平台溢价，1 元对应 100 算力点。
        return (int)round($salePrice * 100);
    }

    private static function chargedPoints(array $order): int
    {
        if (!(int)$order['power_log_id'] || in_array((int)$order['status'], [GeoOrder::STATUS_REJECTED, GeoOrder::STATUS_MISSING], true)) {
            return 0;
        }
        return self::salePoints((float)$order['sale_price']);
    }

    private static function reserve(GeoOrder $order): void
    {
        $points = self::salePoints((float)$order['sale_price']);
        if ($points <= 0) {
            return;
        }
        $tenantId = (int)$order['tenant_id'];
        $userId = (int)$order['user_id'];
        // 每次推送使用自己的来源号，重推不会复用上次已退回的预占流水。
        $source = $order['order_no'] . '-' . (int)($order['push_attempts'] ?? 0);
        PointService::reserveBusinessAmountsInCurrentTransaction(
            $tenantId, $userId, 0, $points, $source, '媒体发稿预占', ['order_no' => $order['order_no']]
        );
        $logId = UserAccountLog::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'change_type' => AccountLogEnum::UM_DEC_APP_CONSUME,
            'source_sn' => $source,
        ])->value('id');
        if (!$logId) {
            throw new Exception('媒体发稿预占流水保存失败');
        }
        $order->save(['power_log_id' => $logId]);
    }

    private static function refund(GeoOrder $order): void
    {
        if (!(int)$order['power_log_id']) {
            return;
        }
        $charge = UserAccountLog::where([
            'id' => $order['power_log_id'],
            'tenant_id' => $order['tenant_id'],
            'user_id' => $order['user_id'],
            'change_type' => AccountLogEnum::UM_DEC_APP_CONSUME,
        ])->findOrFail();
        PointService::releaseReservedBusinessAmountsInCurrentTransaction(
            (int)$order['tenant_id'], (int)$order['user_id'], 0, (float)$charge['change_amount'],
            $charge['source_sn'] . '-refund', '媒体发稿退款', ['order_no' => $order['order_no']]
        );
    }

    /**
     * 带令牌自愈的调用。
     *
     * 批量轮询（geo:order-sync）会在同一个进程里连续查询很多订单，令牌中途过期
     * 或者被别处刷新覆盖时，旧令牌当场作废。这里收到令牌失效就换新令牌重试一次，
     * 其余异常原样抛出交给调用方累计失败数，不会无谓重发请求。
     *
     * @param callable $call 实际请求，签名为 fn(string $openToken): array
     * @return array<string, mixed>
     */
    private static function callWithToken(callable $call): array
    {
        $token = AigcGeoManuscriptService::accessToken();
        try {
            return $call($token);
        } catch (GeoTokenExpiredException $e) {
            try {
                $token = AigcGeoManuscriptService::renewAccessToken();
            } catch (Exception $renewError) {
                throw new Exception('访问令牌已失效且无法重新获取：' . $renewError->getMessage());
            }
            try {
                return $call($token);
            } catch (GeoTokenExpiredException $retryError) {
                throw new Exception('访问令牌已失效，请检查文稿代发账号配置：' . $retryError->getMessage());
            }
        }
    }

    /**
     * 推送失败落库。
     *
     * @param GeoOrder $order 投稿单
     * @param string $message 海讯返回的拒稿/失败原因
     * @param bool $retryable 是否可重试
     */
    private static function fail(GeoOrder $order, string $message, bool $retryable): void
    {
        $from = (int)$order['status'];
        self::refund($order);
        $order->save([
            'status' => GeoOrder::STATUS_REJECTED,
            'remote_flag' => GeoOrder::STATUS_REJECTED,
            'fail_reason' => mb_substr($message, 0, 500),
            'retryable' => $retryable ? 1 : 0,
            'push_attempts' => (int)$order['push_attempts'] + 1,
            'last_push_time' => time(),
            'update_time' => time(),
        ]);
        GeoOrderLog::record($order, $from, GeoOrder::STATUS_REJECTED, 'push', $message);
    }

    /**
     * 哪些推送失败可以重试：资源类/价格类错误重试无意义，账户与网络问题可重试。
     *
     * @param string $message 海讯返回信息
     */
    private static function isRetryableMessage(string $message): bool
    {
        foreach (['资源价格不匹配', '资源已下架', '价格类别', '资源id', '订单号'] as $needle) {
            if (str_contains($message, $needle)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 生成订单号：GEO + 年月日时分秒 + 6 位随机。
     */
    private static function newOrderNo(): string
    {
        do {
            $no = self::ORDER_PREFIX . date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (GeoOrder::where('order_no', $no)->value('id') !== null);
        return $no;
    }
}
