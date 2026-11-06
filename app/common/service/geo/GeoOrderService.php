<?php

namespace app\common\service\geo;

use app\common\exception\GeoTokenExpiredException;
use app\common\model\geo\GeoArticle;
use app\common\model\geo\GeoMedia;
use app\common\model\geo\GeoOrder;
use app\common\model\geo\GeoOrderLog;
use Exception;

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
     * @param array<string, mixed> $params article_id / media_id / price_type / remark / origin
     * @return array<string, mixed>
     */
    public static function create(int $userId, array $params): array
    {
        $articleId = (int)($params['article_id'] ?? 0);
        $mediaId = (int)($params['media_id'] ?? 0);
        $priceType = max(1, (int)($params['price_type'] ?? 1));

        $article = GeoArticle::where('id', $articleId)->findOrEmpty();
        if ($article->isEmpty() || (int)$article['user_id'] !== $userId) {
            throw new Exception('稿件不存在');
        }
        if (trim((string)$article['content']) === '') {
            throw new Exception('稿件正文为空，不能投稿');
        }
        $media = GeoMedia::where('id', $mediaId)->findOrEmpty();
        if ($media->isEmpty()) {
            throw new Exception('媒体资源不存在');
        }
        if ((int)$media['flag'] !== 1 || (int)$media['deleted'] !== 1) {
            throw new Exception('该媒体已下架或已删除，请重新选择');
        }

        // 多价格类别：短视频/微信/微博必须选对类别，否则海讯按错误价格拒稿。
        [$costPrice, $salePrice] = self::resolvePrice($media, $priceType);

        $order = GeoOrder::create([
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
        ]);
        GeoOrderLog::record($order, GeoOrder::STATUS_LOCAL, GeoOrder::STATUS_LOCAL, 'create', '创建投稿单');

        // 推送失败不抛给用户：订单已落库，后台可重试。
        $order = self::push($order);
        return self::detail((int)$order['id'], $userId);
    }

    /**
     * 推送一张本地订单到海讯社。
     *
     * @param GeoOrder $order 投稿单
     * @return GeoOrder 刷新后的订单
     */
    public static function push(GeoOrder $order): GeoOrder
    {
        $from = (int)$order['status'];
        if (!in_array($from, [GeoOrder::STATUS_LOCAL, GeoOrder::STATUS_REJECTED], true)) {
            throw new Exception('只有待推送或被拒稿的订单可以重新推送');
        }
        if (!(bool)$order['retryable'] && $from === GeoOrder::STATUS_REJECTED) {
            throw new Exception('该订单为资源类拒稿，不可自动重推，请更换媒体后重新下单');
        }
        if (trim((string)$order['content_url']) === '') {
            throw new Exception('稿件缺少预览地址，无法投稿');
        }

        // 推送前刷新资源价与状态，避免海讯调价后 priceError。
        $media = GeoMedia::where('id', (int)$order['media_id'])->findOrEmpty();
        if ($media->isEmpty()) {
            throw new Exception('媒体资源不存在');
        }
        if ((int)$media['flag'] !== 1 || (int)$media['deleted'] !== 1) {
            self::fail($order, '媒体已下架', false);
            return $order;
        }
        $costPrice = self::costOf($media, (int)$order['price_type']);
        if (abs($costPrice - (float)$order['cost_price']) > 0.005) {
            $order->save(['cost_price' => $costPrice, 'update_time' => time()]);
            GeoOrderLog::record($order, $from, $from, 'push', sprintf('海讯价格变动 %.2f -> %.2f', (float)$order['cost_price'], $costPrice));
        }

        try {
            self::callWithToken(
                (int)$order['tenant_id'],
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
            // orderExist 说明已经推送过，按待安排处理继续轮询。
            if (str_contains($e->getMessage(), '订单已存在')) {
                $order->save(['status' => GeoOrder::STATUS_PENDING, 'remote_flag' => GeoOrder::STATUS_PENDING, 'update_time' => time()]);
                GeoOrderLog::record($order, $from, GeoOrder::STATUS_PENDING, 'push', '海讯提示订单已存在，转为待安排继续查询');
                return $order;
            }
            self::fail($order, $e->getMessage(), self::isRetryableMessage($e->getMessage()));
            return $order;
        }

        $order->save([
            'status' => GeoOrder::STATUS_PENDING,
            'remote_flag' => GeoOrder::STATUS_PENDING,
            'push_attempts' => (int)$order['push_attempts'] + 1,
            'last_push_time' => time(),
            'fail_reason' => '',
            'retryable' => 0,
            'update_time' => time(),
        ]);
        GeoOrderLog::record($order, $from, GeoOrder::STATUS_PENDING, 'push', '推送海讯成功');
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
        if ((int)$order['status'] === GeoOrder::STATUS_LOCAL) {
            throw new Exception('订单尚未推送，无法查询');
        }
        $result = self::callWithToken(
            (int)$order['tenant_id'],
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
        if ((int)$order['remote_flag'] !== GeoOrder::STATUS_PUBLISHED) {
            throw new Exception('只有"发布成功待确认"的订单可以确认或反馈，当前状态：'
                . (GeoOrder::STATUS_TEXT[(int)$order['status']] ?? $order['status']));
        }
        self::callWithToken(
            (int)$order['tenant_id'],
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
        $data['status_text'] = GeoOrder::STATUS_TEXT[$data['status']] ?? (string)$data['status'];
        $data['can_retry'] = in_array((int)$data['status'], [GeoOrder::STATUS_LOCAL, GeoOrder::STATUS_REJECTED], true)
            && ((int)$data['status'] === GeoOrder::STATUS_LOCAL || (bool)$data['retryable']);
        $data['can_confirm'] = (int)$data['remote_flag'] === GeoOrder::STATUS_PUBLISHED;
        $data['logs'] = GeoOrderLog::where('order_id', $id)
            ->order('id', 'asc')
            ->field('from_status,to_status,action,message,create_time')
            ->select()
            ->toArray();
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
            . 'cost_price,sale_price,title,status,remote_flag,publish_url,fail_reason,can_retry,'
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
            $row['status_text'] = GeoOrder::STATUS_TEXT[$row['status']] ?? (string)$row['status'];
            $row['can_confirm'] = (int)$row['remote_flag'] === GeoOrder::STATUS_PUBLISHED;
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
        $ratio = (float)$media['price_ratio'] > 0 ? (float)$media['price_ratio'] : GeoMediaSyncService::markup((int)$media['tenant_id']);
        foreach ((array)$media->getAttr('price_type_json') as $item) {
            if ((int)($item['type'] ?? 0) === $priceType) {
                $cost = (float)($item['price'] ?? 0);
                return [$cost, GeoMediaSyncService::suggestSalePrice($cost, $ratio)];
            }
        }
        throw new Exception('该媒体没有价格类别 ' . $priceType . '，请重新选择');
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

    /**
     * 带令牌自愈的调用。
     *
     * 批量轮询（geo:order-sync）会在同一个进程里连续查询很多订单，令牌中途过期
     * 或者被别处刷新覆盖时，旧令牌当场作废。这里收到令牌失效就换新令牌重试一次，
     * 其余异常原样抛出交给调用方累计失败数，不会无谓重发请求。
     *
     * @param int $tenantId 租户 ID
     * @param callable $call 实际请求，签名为 fn(string $openToken): array
     * @return array<string, mixed>
     */
    private static function callWithToken(int $tenantId, callable $call): array
    {
        $token = AigcGeoManuscriptService::accessToken($tenantId);
        try {
            return $call($token);
        } catch (GeoTokenExpiredException $e) {
            try {
                $token = AigcGeoManuscriptService::renewAccessToken($tenantId);
            } catch (Exception $renewError) {
                throw new Exception('访问令牌已失效且无法重新获取：' . $renewError->getMessage());
            }
            try {
                return $call($token);
            } catch (GeoTokenExpiredException $retryError) {
                throw new Exception('访问令牌已失效，请检查海讯社账号配置：' . $retryError->getMessage());
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
        } while (!GeoOrder::where('order_no', $no)->isEmpty());
        return $no;
    }
}