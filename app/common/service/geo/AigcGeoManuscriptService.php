<?php

namespace app\common\service\geo;

use app\common\cache\GeoManuscriptTokenCache;
use app\common\service\ConfigService;
use Exception;

/**
 * GEO 文稿代发。对接海讯社开放平台 V1.1。
 */
class AigcGeoManuscriptService
{
    public const BASE_URL = 'https://api.haipress.com';

    /** 文档要求的客户端类型，放在请求头。 */
    public const CLIENT_TYPE = '5';

    /** 令牌有效秒数。到期前 100 秒刷新。 */
    public const TOKEN_TTL = 7200;

    /** 提前刷新的秒数。 */
    public const TOKEN_REFRESH_AHEAD = 100;

    /**
     * 资源类型对应 list{n}ON。
     *
     * @var array<int, string>
     */
    public const RESOURCE_TYPES = [
        1 => '软文',
        2 => '自媒体',
        3 => '海外发稿',
        4 => '海外社媒',
        5 => '贴吧',
        6 => '论坛',
        7 => '问答',
        8 => '友链',
        9 => '文案',
        10 => '小红书',
        11 => '百科',
        12 => '短视频',
        13 => '微信',
        14 => '微博',
        15 => '母语',
    ];

    /**
     * 订单状态。
     *
     * @var array<int, string>
     */
    public const ORDER_FLAGS = [
        100 => '订单不存在',
        0 => '待安排',
        1 => '已安排',
        2 => '拒稿',
        3 => '发布成功，等待确认',
        4 => '反馈处理中',
        5 => '已完成',
    ];

    /**
     * 后台查看代发配置。回传已保存的 token、key 原文，不返回访问令牌。
     *
     * @param int $tenantId 租户 ID
     * @return array<string, mixed>
     */
    public static function configDetail(int $tenantId): array
    {
        return self::present(self::account());
    }

    /**
     * 保存海讯社 token、key。空值表示沿用已保存的值。保存前会先换取访问令牌。
     *
     * @param int $tenantId 租户 ID
     * @param array<string, mixed> $params token、key
     * @return array<string, mixed>
     */
    public static function saveConfig(int $tenantId, array $params): array
    {
        $current = self::account();
        $token = self::incomingSecret($params, 'token', (string)($current['token'] ?? ''));
        $key = self::incomingSecret($params, 'key', (string)($current['key'] ?? ''));
        if ($token === '' || $key === '') {
            throw new Exception('请填写海讯社 token 和 key');
        }
        $openToken = self::issueToken($token, $key);
        $account = ['token' => $token, 'key' => $key];
        ConfigService::set('geo_manuscript', 'account', $account);
        (new GeoManuscriptTokenCache())->setAccessToken($tenantId, $openToken, self::TOKEN_TTL);
        return self::present($account);
    }

    /**
     * 取出当前租户可用的访问令牌。距过期不足 100 秒时先刷新，刷新失败再用 token、key 重换。
     *
     * @param int $tenantId 租户 ID
     */
    public static function accessToken(int $tenantId): string
    {
        $account = self::account();
        $accountToken = trim((string)($account['token'] ?? ''));
        $accountKey = trim((string)($account['key'] ?? ''));
        if ($accountToken === '' || $accountKey === '') {
            throw new Exception('请先配置海讯社账号');
        }
        $tokenCache = new GeoManuscriptTokenCache();
        $cached = $tokenCache->getAccessToken($tenantId);
        $openToken = is_array($cached) ? trim((string)($cached['token'] ?? '')) : '';
        $issuedAt = is_array($cached) ? (int)($cached['issued_at'] ?? 0) : 0;
        if ($openToken !== '' && $issuedAt > 0 && time() < $issuedAt + self::TOKEN_TTL - self::TOKEN_REFRESH_AHEAD) {
            return $openToken;
        }
        if ($openToken === '') {
            $next = self::issueToken($accountToken, $accountKey);
        } else {
            try {
                $next = self::refreshToken($openToken);
            } catch (Exception) {
                $next = self::issueToken($accountToken, $accountKey);
            }
        }
        $tokenCache->setAccessToken($tenantId, $next, self::TOKEN_TTL);
        return $next;
    }

    /**
     * 用海讯社分配的 token、key 换访问令牌。
     *
     * @param string $token 海讯社 token
     * @param string $key 海讯社 key
     * @return string openToken
     */
    public static function issueToken(string $token, string $key): string
    {
        $token = trim($token);
        $key = trim($key);
        if ($token === '' || $key === '') {
            throw new Exception('海讯社 token 与 key 不能为空');
        }
        $payload = self::request('POST', '/api/member/user/tokenON', [
            'token' => $token,
            'key' => $key,
        ]);
        return self::tokenValue($payload);
    }

    /**
     * 刷新访问令牌。旧令牌仍放在请求头。
     *
     * @param string $openToken 当前访问令牌
     * @return string 新的 openToken
     */
    public static function refreshToken(string $openToken): string
    {
        $payload = self::request('GET', '/api/member/user/refreshTokenON', [], $openToken);
        return self::tokenValue($payload);
    }

    /**
     * 分页拉取某一类媒体资源。
     *
     * @param string $openToken 访问令牌
     * @param int $type 资源类型，1 到 15
     * @param int $page 页码
     * @param int $limit 每页条数，不超过 3000
     * @param int|null $own 特色资源：0 否，1 是；不传则不筛选
     * @return array{total:int, list:array<int, array<string, mixed>>}
     */
    public static function resources(string $openToken, int $type, int $page, int $limit, ?int $own = null): array
    {
        if (!isset(self::RESOURCE_TYPES[$type])) {
            throw new Exception('资源类型无效');
        }
        if ($page < 1) {
            throw new Exception('页码无效');
        }
        if ($limit < 1 || $limit > 3000) {
            throw new Exception('每页条数须为 1 至 3000');
        }
        $body = ['page' => $page, 'limit' => $limit];
        if ($own !== null) {
            if (!in_array($own, [0, 1], true)) {
                throw new Exception('特色资源参数无效');
            }
            $body['own'] = $own;
        }
        $payload = self::request('POST', '/api/media/resources/list' . $type . 'ON', $body, $openToken);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $list = is_array($data['list'] ?? null) ? array_values($data['list']) : [];
        return ['total' => (int)($data['total'] ?? 0), 'list' => $list];
    }

    /**
     * 查询单个资源是否仍可下单。
     *
     * @param string $openToken 访问令牌
     * @param string $sourceId 海讯资源 id
     * @return array{flag:int, deleted:int, available:bool}
     */
    public static function resourceState(string $openToken, string $sourceId): array
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '') {
            throw new Exception('请选择媒体资源');
        }
        $payload = self::request('POST', '/api/media/resources/stateON', ['sourceid' => $sourceId], $openToken);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $flag = (int)($data['flag'] ?? 0);
        $deleted = (int)($data['deleted'] ?? 0);
        return [
            'flag' => $flag,
            'deleted' => $deleted,
            'available' => $flag === 1 && $deleted === 1,
        ];
    }

    /**
     * 推送一篇文稿。content 传可公网打开的预览地址，不是正文。
     * title、content、remark、origin 会按文档单独 urlencode。
     *
     * @param string $openToken 访问令牌
     * @param array $order ordernum、title、content、price、sourceid 必填；priceType、remark、origin 选填
     */
    public static function createOrder(string $openToken, array $order): void
    {
        foreach (['ordernum', 'title', 'content', 'sourceid'] as $field) {
            if (trim((string)($order[$field] ?? '')) === '') {
                throw new Exception('代发订单参数不完整');
            }
        }
        if (!is_numeric($order['price'] ?? null)) {
            throw new Exception('资源价格无效');
        }
        $body = [
            'ordernum' => trim((string)$order['ordernum']),
            'title' => urlencode(trim((string)$order['title'])),
            'content' => urlencode(trim((string)$order['content'])),
            'price' => (float)$order['price'],
            'sourceid' => trim((string)$order['sourceid']),
        ];
        if (array_key_exists('priceType', $order) && $order['priceType'] !== '' && $order['priceType'] !== null) {
            $priceType = (int)$order['priceType'];
            if ($priceType <= 0) {
                throw new Exception('价格类别无效');
            }
            $body['priceType'] = $priceType;
        }
        foreach (['remark', 'origin'] as $field) {
            $value = trim((string)($order[$field] ?? ''));
            if ($value !== '') {
                $body[$field] = urlencode($value);
            }
        }
        self::request('POST', '/api/media/order/createON', $body, $openToken, true);
    }

    /**
     * 按接入方订单号查询代发进度。
     *
     * @param string $openToken 访问令牌
     * @param string $orderNum 接入方订单号
     * @return array{flag:int, status:string, url:string, result:string}
     */
    public static function queryOrder(string $openToken, string $orderNum): array
    {
        $orderNum = trim($orderNum);
        if ($orderNum === '') {
            throw new Exception('请填写订单号');
        }
        $payload = self::request('POST', '/api/media/order/queryON', ['ordernum' => $orderNum], $openToken);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $flag = (int)($data['flag'] ?? 100);
        return [
            'flag' => $flag,
            'status' => self::ORDER_FLAGS[$flag] ?? '未知状态',
            'url' => (string)($data['url'] ?? ''),
            'result' => (string)($data['result'] ?? ''),
        ];
    }

    /**
     * 确认发布或提交修改意见。仅订单状态为 3 时海讯社接受更新。
     *
     * @param string $openToken 访问令牌
     * @param string $orderNum 接入方订单号
     * @param int $flag 4 反馈，5 已完成
     * @param string $feedback flag 为 4 时的修改意见
     */
    public static function processOrder(string $openToken, string $orderNum, int $flag, string $feedback = ''): void
    {
        $orderNum = trim($orderNum);
        if ($orderNum === '') {
            throw new Exception('请填写订单号');
        }
        if (!in_array($flag, [4, 5], true)) {
            throw new Exception('只接受反馈或确认完成');
        }
        $body = ['ordernum' => $orderNum, 'flag' => $flag];
        $feedback = trim($feedback);
        if ($feedback !== '') {
            $body['releaseresult'] = urlencode($feedback);
        }
        self::request('POST', '/api/media/order/processON', $body, $openToken, true);
    }

    /**
     * 租户文稿代发账号，存在 la_tenant_config。
     *
     * @return array<string, mixed>
     */
    private static function account(): array
    {
        $value = ConfigService::get('geo_manuscript', 'account', []);
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $params 请求参数
     * @param string $field token 或 key
     * @param string $current 已保存的值
     */
    private static function incomingSecret(array $params, string $field, string $current): string
    {
        if (!array_key_exists($field, $params)) {
            return trim($current);
        }
        $value = trim((string)$params[$field]);
        return $value === '' ? trim($current) : $value;
    }

    /**
     * 配置接口回传 token、key 原文。访问令牌由后台自行换取和刷新，不给配置页。
     *
     * @param array<string, mixed> $account 代发账号
     * @return array<string, mixed>
     */
    private static function present(array $account): array
    {
        $token = trim((string)($account['token'] ?? ''));
        $key = trim((string)($account['key'] ?? ''));
        return [
            'configured' => $token !== '' && $key !== '',
            'token' => $token,
            'key' => $key,
        ];
    }

    /**
     * @param array<string, mixed> $payload 海讯社响应
     */
    private static function tokenValue(array $payload): string
    {
        $token = trim((string)($payload['data'] ?? ''));
        if ($token === '') {
            throw new Exception('未获取到访问令牌');
        }
        return $token;
    }

    /**
     * @param array<string, mixed> $body 表单或 JSON 正文
     * @return array<string, mixed>
     */
    private static function request(string $method, string $path, array $body, string $openToken = '', bool $json = false): array
    {
        $headers = ['clientType: ' . self::CLIENT_TYPE, 'Accept: application/json'];
        $openToken = trim($openToken);
        if ($openToken !== '') {
            $headers[] = 'openToken: ' . $openToken;
        }
        $url = self::BASE_URL . $path;
        $method = strtoupper($method);
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method !== 'GET') {
            if ($json) {
                $headers[] = 'Content-Type: application/json';
                $options[CURLOPT_HTTPHEADER] = $headers;
                $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $options[CURLOPT_POSTFIELDS] = http_build_query($body);
            }
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            throw new Exception($error !== '' ? $error : '文稿代发请求失败');
        }
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            throw new Exception('文稿代发返回无法解析');
        }
        if ((int)($payload['code'] ?? 0) !== 200) {
            $message = trim((string)($payload['msgcn'] ?? ''));
            if ($message === '') {
                $message = trim((string)($payload['msgen'] ?? ''));
            }
            throw new Exception($message !== '' ? $message : '文稿代发失败');
        }
        return $payload;
    }
}
