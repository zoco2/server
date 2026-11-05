<?php

namespace app\api\logic\merchant;

use app\common\model\merchant\MerchantStaff;
use app\common\model\merchant\MerchantStore;
use app\common\model\wechat\WechatAuthorizer;
use app\common\service\SubmitLockService;
use app\common\service\wechat\OpenPlatformService;
use app\common\service\wechat\WeChatConfigService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use WpOrg\Requests\Requests;

/** Employee-to-official-account binding flow. */
class StaffWechatBindingLogic
{
    private const SCENE_PREFIX = 'merchant_staff_bind_';
    private const TOKEN_PREFIX = 'merchant.staff.bind.';
    private const ACCESS_TOKEN_PREFIX = 'merchant.staff.official_account.access_token.';
    private const QR_TTL = 600;

    public static function createQrcode(int $staffId): array
    {
        $tenantId = (int)request()->tenantId;
        $staff = MerchantScope::query(MerchantStaff::class)->where('id', $staffId)->findOrEmpty();
        if ($staff->isEmpty()) {
            throw new \RuntimeException('员工不存在或无权限');
        }
        if (trim((string)$staff['openid']) !== '' || trim((string)$staff['appid']) !== '') {
            throw new \RuntimeException('该员工已绑定公众号');
        }
        $authorizerAppId = self::bindingOfficialAppId($tenantId);
        self::resolveBindTemplate($tenantId, false, $authorizerAppId);

        $token = bin2hex(random_bytes(16));
        $scene = self::SCENE_PREFIX . $token;
        $record = [
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'appid' => $authorizerAppId,
            'expires_at' => time() + self::QR_TTL,
        ];
        $qr = self::wechatRequest($tenantId, 'cgi-bin/qrcode/create', [
            'expire_seconds' => self::QR_TTL,
            'action_name' => 'QR_STR_SCENE',
            'action_info' => ['scene' => ['scene_str' => $scene]],
        ], 'merchant.staff.bind.qrcode', 'POST', $authorizerAppId);

        $ticket = trim((string)($qr['ticket'] ?? ''));
        if ($ticket === '') {
            throw new \RuntimeException('公众号未返回绑定二维码');
        }
        if (!Cache::set(self::TOKEN_PREFIX . $token, $record, self::QR_TTL)) {
            throw new \RuntimeException('绑定二维码暂不可用，请重试');
        }

        return [
            'qrcode_url' => 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=' . rawurlencode($ticket),
            'expire_seconds' => self::QR_TTL,
        ];
    }

    /**
     * Add the code-defined template to this tenant's official account and save its returned ID.
     */
    public static function syncTemplate(): array
    {
        $tenantId = (int)request()->tenantId;
        return self::resolveBindTemplate($tenantId, true, self::bindingOfficialAppId($tenantId));
    }

    public static function testSend(int $staffId): array
    {
        $tenantId = (int)request()->tenantId;
        $staff = MerchantScope::query(MerchantStaff::class)->where('id', $staffId)->findOrEmpty();
        if ($staff->isEmpty()) {
            throw new \RuntimeException('员工不存在或无权限');
        }
        $appid = trim((string)$staff['appid']);
        $openid = trim((string)$staff['openid']);
        if ($appid === '' || $openid === '') {
            throw new \RuntimeException('请先绑定公众号');
        }
        self::sendBindNotice($tenantId, $appid, $openid, (string)$staff['name'], (int)$staff['store_id']);
        return ['sent' => true];
    }

    /**
     * Process only employee-binding subscribe/scan events. Null means the event is unrelated.
     */
    public static function handleCallback(int $tenantId, string $appid, array $message): ?string
    {
        $event = strtolower(trim((string)($message['Event'] ?? '')));
        if (!in_array($event, ['subscribe', 'scan'], true)) {
            return null;
        }
        $eventKey = trim((string)($message['EventKey'] ?? ''));
        if ($event === 'subscribe' && str_starts_with($eventKey, 'qrscene_')) {
            $eventKey = substr($eventKey, 8);
        }
        if (!str_starts_with($eventKey, self::SCENE_PREFIX)) {
            return null;
        }

        $token = substr($eventKey, strlen(self::SCENE_PREFIX));
        $openid = trim((string)($message['FromUserName'] ?? ''));
        if ($openid === '' || !preg_match('/^[a-f0-9]{32}$/D', $token)) {
            return '绑定二维码无效，请返回员工页面重新获取。';
        }

        $cacheKey = self::TOKEN_PREFIX . $token;
        $binding = Cache::get($cacheKey);
        if (!is_array($binding)
            || (int)($binding['tenant_id'] ?? 0) !== $tenantId
            || !hash_equals((string)($binding['appid'] ?? ''), $appid)
            || (int)($binding['expires_at'] ?? 0) < time()) {
            return '绑定二维码已失效，请返回员工页面重新获取。';
        }

        $lock = null;
        try {
            $lock = SubmitLockService::acquire('merchant.staff.bind', $tenantId, 0, true);
            $staff = MerchantStaff::withoutGlobalScope()
                ->where('tenant_id', $tenantId)
                ->where('id', (int)$binding['staff_id'])
                ->findOrEmpty();
            if ($staff->isEmpty() || trim((string)$staff['appid']) !== '' || trim((string)$staff['openid']) !== '') {
                Cache::delete($cacheKey);
                return '该员工已绑定公众号，请勿重复绑定。';
            }

            $alreadyBound = MerchantStaff::withoutGlobalScope()
                ->where('tenant_id', $tenantId)
                ->where('appid', $appid)
                ->where('openid', $openid)
                ->findOrEmpty();
            if (!$alreadyBound->isEmpty()) {
                Cache::delete($cacheKey);
                return '该微信已绑定员工，请勿重复绑定。';
            }

            Db::transaction(static function () use ($staff, $appid, $openid): void {
                $staff->save(['appid' => $appid, 'openid' => $openid]);
            });
            Cache::delete($cacheKey);
        } catch (\Throwable $e) {
            Log::error('[merchant.staff.bind] persistence failed tenant_id=' . $tenantId . ' staff_id=' . (int)$binding['staff_id'] . ' error=' . $e->getMessage());
            return '绑定暂时未完成，请返回员工页面重试。';
        } finally {
            SubmitLockService::release($lock);
        }

        try {
            self::sendBindNotice($tenantId, $appid, $openid, (string)$staff['name'], (int)$staff['store_id']);
        } catch (\Throwable $e) {
            Log::error('[merchant.staff.bind] template notification failed tenant_id=' . $tenantId . ' staff_id=' . (int)$staff['id'] . ' error=' . $e->getMessage());
        }

        return '';
    }

    private static function sendBindNotice(int $tenantId, string $appid, string $openid, string $staffName, int $storeId): void
    {
        $template = self::resolveBindTemplate($tenantId, false, $appid);
        $templateId = $template['template_id'];
        $fieldMap = $template['field_map'];
        $hasContentField = $template['has_content_field'];

        $storeName = (string)MerchantStore::withoutGlobalScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $storeId)
            ->value('name');
        if ($hasContentField) {
            $field = trim((string)$fieldMap['content']);
            $data = [$field => ['value' => '您已成功绑定公众号通知。']];
        } else {
            $data = [];
            foreach ([
                'first' => '员工公众号绑定成功',
                'task_name' => '公众号通知绑定',
                'audit_result' => '绑定成功',
                'audit_time' => date('Y-m-d H:i:s'),
                'staff_name' => $staffName,
                'store_name' => $storeName,
                'bind_time' => date('Y-m-d H:i:s'),
                'remark' => '您已成功绑定公众号通知。',
            ] as $source => $value) {
                $field = trim((string)($fieldMap[$source] ?? ''));
                if ($field !== '') {
                    $data[$field] = ['value' => mb_substr((string)$value, 0, 20)];
                }
            }
        }

        try {
            self::wechatRequest($tenantId, 'cgi-bin/message/template/send', [
                'touser' => $openid,
                'template_id' => $templateId,
                'data' => $data,
            ], 'merchant.staff.bind.template.send', 'POST', $appid);
        } catch (\Throwable $e) {
            if (!self::isTemplateInvalidMessage($e->getMessage())) {
                throw $e;
            }
            Log::warning('[merchant.staff.bind] invalid template_id tenant_id=' . $tenantId . ' template_id=' . $templateId . ' error=' . $e->getMessage());
            $template = self::resolveBindTemplate($tenantId, true, $appid);
            self::wechatRequest($tenantId, 'cgi-bin/message/template/send', [
                'touser' => $openid,
                'template_id' => $template['template_id'],
                'data' => $data,
            ], 'merchant.staff.bind.template.send.retry', 'POST', $appid);
        }
    }

    private static function resolveBindTemplate(int $tenantId, bool $forceSync = false, string $appid = ''): array
    {
        $definition = (array)config('merchant.staff_bind_notice', []);
        $shortId = trim((string)($definition['template_short_id'] ?? ''));
        $keywords = array_values(array_filter(array_map('trim', (array)($definition['keyword_name_list'] ?? []))));
        $title = trim((string)($definition['title'] ?? ''));
        $fieldMap = (array)($definition['field_map'] ?? []);
        $hasContentField = !empty($fieldMap['content']);
        $hasStructuredFields = !empty($fieldMap['staff_name']) && !empty($fieldMap['store_name']) && !empty($fieldMap['bind_time']);
        if ($title === '' || (!$hasContentField && !$hasStructuredFields)) {
            throw new \RuntimeException('员工绑定模板尚未配置标题和字段映射');
        }

        $appid = trim($appid) !== '' ? trim($appid) : self::bindingOfficialAppId($tenantId);
        $existing = self::wechatRequest(
            $tenantId,
            'cgi-bin/template/get_all_private_template',
            [],
            'merchant.staff.bind.template.list',
            'GET',
            $appid
        );
        $templates = (array)($existing['template_list'] ?? []);
        $savedTemplateIds = self::savedBindTemplateIds($tenantId, $appid);

        if (!$forceSync) {
            foreach ($savedTemplateIds as $savedTemplateId) {
                if ($savedTemplateId === '') {
                    continue;
                }
                foreach ($templates as $template) {
                    $templateTitle = trim((string)($template['title'] ?? ''));
                    if (hash_equals($savedTemplateId, trim((string)($template['template_id'] ?? ''))) && $templateTitle === $title) {
                        self::saveBindTemplateId($tenantId, $appid, $savedTemplateId);
                        return [
                            'template_id' => $savedTemplateId,
                            'title' => $templateTitle,
                            'field_map' => $fieldMap,
                            'has_content_field' => $hasContentField,
                        ];
                    }
                }
                Log::warning('[merchant.staff.bind] saved template_id not found tenant_id=' . $tenantId . ' appid=' . $appid . ' template_id=' . $savedTemplateId);
            }
        }

        $templateId = '';
        foreach ($templates as $template) {
            if (trim((string)($template['title'] ?? '')) === $title) {
                $templateId = trim((string)($template['template_id'] ?? ''));
                break;
            }
        }

        if ($templateId === '') {
            if ($shortId === '' || $keywords === []) {
                throw new \RuntimeException('当前公众号未找到「' . $title . '」模板，请先在公众号后台添加后再同步通知模板');
            }
            $created = self::wechatRequest($tenantId, 'cgi-bin/template/api_add_template', [
                'template_id_short' => $shortId,
                'keyword_name_list' => $keywords,
            ], 'merchant.staff.bind.template.add');
            $templateId = trim((string)($created['template_id'] ?? ''));
        }
        if ($templateId === '') {
            throw new \RuntimeException('微信未返回员工绑定模板 ID');
        }

        self::saveBindTemplateId($tenantId, $appid, $templateId);
        return [
            'template_id' => $templateId,
            'title' => $title,
            'field_map' => $fieldMap,
            'has_content_field' => $hasContentField,
        ];
    }

    private static function savedBindTemplateIds(int $tenantId, string $appid): array
    {
        $names = [self::bindTemplateConfigName($appid), 'staff_bind_template_id'];
        $values = [];
        foreach ($names as $name) {
            $values[] = trim((string)\app\common\model\TenantConfig::withoutGlobalScope()
                ->where(['tenant_id' => $tenantId, 'type' => 'merchant', 'name' => $name])
                ->value('value'));
        }
        return array_values(array_unique($values));
    }

    private static function saveBindTemplateId(int $tenantId, string $appid, string $templateId): void
    {
        foreach ([self::bindTemplateConfigName($appid), 'staff_bind_template_id'] as $name) {
            $query = ['tenant_id' => $tenantId, 'type' => 'merchant', 'name' => $name];
            $row = \app\common\model\TenantConfig::withoutGlobalScope()->where($query)->findOrEmpty();
            if ($row->isEmpty()) {
                \app\common\model\TenantConfig::create($query + ['value' => $templateId]);
                continue;
            }
            $row->save(['value' => $templateId]);
        }
    }

    private static function bindTemplateConfigName(string $appid): string
    {
        return 'staff_bind_tpl_' . substr(sha1($appid), 0, 16);
    }

    private static function isTemplateInvalidMessage(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'invalid template_id')
            || (str_contains($message, 'template_id') && str_contains($message, 'invalid'))
            || (str_contains($message, '模板') && (str_contains($message, '无效') || str_contains($message, '不存在')));
    }

    private static function bindingOfficialAppId(int $tenantId): string
    {
        $config = WeChatConfigService::getOaConfig();
        if (!empty($config['app_id']) && !empty($config['secret'])) {
            return (string)$config['app_id'];
        }
        $authorizerAppId = self::authorizedOfficialAppId($tenantId);
        if ($authorizerAppId !== '') {
            return $authorizerAppId;
        }
        throw new \RuntimeException('请先在公众号配置中填写 AppID 和 AppSecret');
    }

    private static function authorizedOfficialAppId(int $tenantId): string
    {
        return (string)WechatAuthorizer::withoutGlobalScope()
            ->where(['tenant_id' => $tenantId, 'authorizer_type' => 'official', 'authorization_status' => 1])
            ->order('id desc')
            ->value('authorizer_appid');
    }

    private static function manualOfficialConfigForAppid(string $appid): array
    {
        $config = WeChatConfigService::getOaConfig();
        if (!empty($config['app_id']) && !empty($config['secret']) && hash_equals((string)$config['app_id'], $appid)) {
            return $config;
        }
        return [];
    }

    private static function wechatRequest(int $tenantId, string $path, array $payload, string $apiName, string $method = 'POST', string $appid = ''): array
    {
        $appid = trim($appid);
        if ($appid !== '') {
            $manualConfig = self::manualOfficialConfigForAppid($appid);
            if ($manualConfig !== []) {
                return self::manualWechatRequest($tenantId, $manualConfig, $path, $payload, $method, $apiName);
            }
            if (hash_equals(self::authorizedOfficialAppId($tenantId), $appid)) {
                return OpenPlatformService::authorizerRequest($tenantId, 'official', $path, $payload, $apiName, $method);
            }
            throw new \RuntimeException('员工绑定公众号与当前公众号配置不一致，请重新绑定');
        }

        $config = WeChatConfigService::getOaConfig();
        if (!empty($config['app_id']) && !empty($config['secret'])) {
            return self::manualWechatRequest($tenantId, $config, $path, $payload, $method, $apiName);
        }
        if (OpenPlatformService::hasAuthorizedOfficialAccount($tenantId)) {
            return OpenPlatformService::authorizerRequest($tenantId, 'official', $path, $payload, $apiName, $method);
        }
        throw new \RuntimeException('请先在公众号配置中填写 AppID 和 AppSecret');
    }

    private static function manualWechatRequest(int $tenantId, array $config, string $path, array $payload, string $method, string $apiName, bool $retried = false): array
    {
        $token = self::manualAccessToken($tenantId, $config);
        $query = ['access_token' => $token];
        $url = 'https://api.weixin.qq.com/' . ltrim($path, '/');
        try {
            if (strtoupper($method) === 'GET') {
                $response = Requests::get($url . '?' . http_build_query($query + $payload), [], ['timeout' => 15, 'connect_timeout' => 5]);
            } else {
                $response = Requests::post(
                    $url . '?' . http_build_query($query),
                    ['Content-Type' => 'application/json'],
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ['timeout' => 15, 'connect_timeout' => 5]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[merchant.staff.bind] manual official account request failed api=' . $apiName . ' tenant_id=' . $tenantId . ' error=' . $e->getMessage());
            throw new \RuntimeException('微信接口请求失败，请稍后重试');
        }

        $result = json_decode((string)$response->body, true);
        if ($response->status_code !== 200 || !is_array($result)) {
            throw new \RuntimeException('微信接口响应异常，请稍后重试');
        }
        if (self::isAccessTokenInvalid($result) && !$retried) {
            Cache::delete(self::manualAccessTokenCacheKey($tenantId, $config));
            self::manualAccessToken($tenantId, $config, true);
            return self::manualWechatRequest($tenantId, $config, $path, $payload, $method, $apiName, true);
        }
        if ((int)($result['errcode'] ?? 0) !== 0) {
            throw new \RuntimeException('微信接口调用失败：' . (string)($result['errmsg'] ?? $result['errcode']));
        }
        return $result;
    }

    private static function manualAccessToken(int $tenantId, array $config, bool $forceRefresh = false): string
    {
        $cacheKey = self::manualAccessTokenCacheKey($tenantId, $config);
        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        try {
            $response = Requests::post(
                'https://api.weixin.qq.com/cgi-bin/stable_token',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'grant_type' => 'client_credential',
                    'appid' => (string)$config['app_id'],
                    'secret' => (string)$config['secret'],
                    'force_refresh' => $forceRefresh,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ['timeout' => 15, 'connect_timeout' => 5]
            );
        } catch (\Throwable $e) {
            Log::warning('[merchant.staff.bind] stable token request failed tenant_id=' . $tenantId . ' error=' . $e->getMessage());
            throw new \RuntimeException('公众号 access_token 获取失败，请稍后重试');
        }

        $result = json_decode((string)$response->body, true);
        if ($response->status_code !== 200 || !is_array($result)) {
            throw new \RuntimeException('公众号 access_token 响应异常，请稍后重试');
        }
        if ((int)($result['errcode'] ?? 0) !== 0) {
            throw new \RuntimeException('公众号 access_token 获取失败：' . (string)($result['errmsg'] ?? $result['errcode']));
        }
        $token = trim((string)($result['access_token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('公众号 access_token 为空，请稍后重试');
        }
        Cache::set($cacheKey, $token, max(60, (int)($result['expires_in'] ?? 7200) - 300));
        return $token;
    }

    private static function manualAccessTokenCacheKey(int $tenantId, array $config): string
    {
        return self::ACCESS_TOKEN_PREFIX . $tenantId . '.' . sha1((string)($config['app_id'] ?? ''));
    }

    private static function isAccessTokenInvalid(array $result): bool
    {
        $code = (int)($result['errcode'] ?? 0);
        if (in_array($code, [40001, 40014, 42001], true)) {
            return true;
        }
        $message = strtolower((string)($result['errmsg'] ?? ''));
        if ($message === '' || !str_contains($message, 'access_token')) {
            return false;
        }
        return str_contains($message, 'invalid')
            || str_contains($message, 'not latest')
            || str_contains($message, 'expired')
            || str_contains($message, '不合法')
            || str_contains($message, '失效')
            || str_contains($message, '过期')
            || str_contains($message, '不是最新');
    }

    private static function assertBindNoticeReady(int $tenantId): void
    {
        $templateId = trim((string)\app\common\model\TenantConfig::withoutGlobalScope()
            ->where(['tenant_id' => $tenantId, 'type' => 'merchant', 'name' => 'staff_bind_template_id'])
            ->value('value'));
        $fieldMap = (array)(config('merchant.staff_bind_notice.field_map') ?? []);
        $hasContentField = !empty($fieldMap['content']);
        $hasStructuredFields = !empty($fieldMap['staff_name']) && !empty($fieldMap['store_name']) && !empty($fieldMap['bind_time']);
        if ($templateId === '' || (!$hasContentField && !$hasStructuredFields)) {
            throw new \RuntimeException('请先配置并同步员工绑定通知模板');
        }
    }
}
