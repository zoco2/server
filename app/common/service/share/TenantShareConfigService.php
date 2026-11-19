<?php

declare(strict_types=1);

namespace app\common\service\share;

use app\common\service\wechat\WechatCredentialService;
use app\common\service\TenantConfigTable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** 分享应用配置；显式租户作用域，兼容独立分表及无 HTTP 请求的任务。 */
final class TenantShareConfigService
{
    private const PLATFORMS = [
        'douyin' => ['key' => 'client_key', 'secret' => 'client_secret', 'name' => '抖音'],
        'xiaohongshu' => ['key' => 'app_key', 'secret' => 'app_secret', 'name' => '小红书'],
    ];

    public function __construct(private readonly int $tenantId)
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('缺少有效的租户信息');
        }
    }

    public function display(): array
    {
        $table = TenantConfigTable::forTenant($this->tenantId);
        $result = [];
        foreach (self::PLATFORMS as $platform => $fields) {
            $result[$platform] = $this->publicConfig($this->read($table, $platform), $fields);
        }
        return $result;
    }

    /** 一次只修改一个平台；空密钥保持原值，密钥永不返回前端。 */
    public function save(array $params): array
    {
        $platform = $params['platform'] ?? null;
        if (!is_string($platform) || !isset(self::PLATFORMS[$platform])) {
            throw new InvalidArgumentException('请选择抖音或小红书');
        }
        $fields = self::PLATFORMS[$platform];
        $key = $this->credential($params[$fields['key']] ?? null, 128, '应用标识');
        $secret = $this->credential($params[$fields['secret']] ?? '', 256, '应用密钥');

        return Db::transaction(function () use ($platform, $fields, $key, $secret): array {
            // 锁住租户行，首次插入配置也可串行化，防止重复配置或覆盖另一个编辑者的密钥。
            $table = TenantConfigTable::forTenant($this->tenantId, true);
            $old = $this->read($table, $platform);
            if ($secret === '' && $key !== (string)($old[$fields['key']] ?? '')
                && (string)($old[$fields['secret']] ?? '') !== '') {
                throw new InvalidArgumentException('更换应用标识时请同时填写对应的新密钥');
            }
            $encrypted = (string)($old[$fields['secret']] ?? '');
            if ($secret !== '') {
                $cipher = WechatCredentialService::encrypt($secret);
                if ($cipher === '') {
                    throw new RuntimeException('分享密钥加密失败');
                }
                $encrypted = 'enc:v1:' . $cipher;
            }
            if ($key === '' || $encrypted === '') {
                throw new InvalidArgumentException('请填写分享应用标识和密钥');
            }
            $value = ['enabled' => 1, $fields['key'] => $key, $fields['secret'] => $encrypted];
            $where = ['tenant_id' => $this->tenantId, 'type' => 'social_share', 'name' => $platform];
            $id = Db::name($table)->where($where)->value('id');
            $row = ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'update_time' => time()];
            if ($id) {
                Db::name($table)->where($where)->where('id', $id)->update($row);
            } else {
                Db::name($table)->insert($where + $row + ['create_time' => time()]);
            }
            return $this->publicConfig($value, $fields);
        });
    }

    /** 用当前租户的凭据签名。 */
    public function runtime(string $platform): array
    {
        if (!isset(self::PLATFORMS[$platform])) {
            throw new InvalidArgumentException('不支持的分享平台');
        }
        $fields = self::PLATFORMS[$platform];
        $value = $this->read(TenantConfigTable::forTenant($this->tenantId), $platform);
        $cipher = (string)($value[$fields['secret']] ?? '');
        $secret = str_starts_with($cipher, 'enc:v1:') ? WechatCredentialService::decrypt(substr($cipher, 7)) : '';
        if (empty($value[$fields['key']]) || $secret === '') {
            throw new RuntimeException($fields['name'] . '分享凭据不可用，请在租户后台重新配置');
        }
        return [
            $fields['key'] => $value[$fields['key']],
            $fields['secret'] => $secret,
            // 缓存驱动由部署配置控制，不允许租户传入连接名。
            'cache_store' => (string)config($platform . '.cache_store', ''),
        ];
    }

    private function read(string $table, string $platform): array
    {
        $value = Db::name($table)->where([
            'tenant_id' => $this->tenantId, 'type' => 'social_share', 'name' => $platform,
        ])->value('value');
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string)$value, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('分享配置数据异常');
        }
        return $decoded;
    }

    private function publicConfig(array $value, array $fields): array
    {
        $key = (string)($value[$fields['key']] ?? '');
        $secretSet = (string)($value[$fields['secret']] ?? '') !== '';
        return [
            'enabled' => 1, // 兼容旧版租户后台。
            $fields['key'] => $key,
            $fields['secret'] . '_set' => $secretSet,
            'configured' => $key !== '' && $secretSet,
        ];
    }

    private function credential(mixed $value, int $maxLength, string $label): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . '格式错误');
        }
        $value = trim($value);
        if (strlen($value) > $maxLength || preg_match('/[\x00-\x20\x7f*]/', $value)) {
            throw new InvalidArgumentException($label . '格式错误，请填写真实凭据');
        }
        return $value;
    }
}
