<?php

namespace app\common\service\smart_edit;

use Exception;
use app\common\model\TenantConfig;

/**
 * 智能剪辑的配音音色目录。
 *
 * 与字体库同理：不读第三方应用表，清单写在代码里，租户可在后台追加。
 * key 是提交时用的 voice_key；provider_asset_id 是火山大模型的 speaker ID。
 *
 * 火山 2.0 音色必须配 seed-tts-2.0 资源 ID，否则会报音色不存在。
 * 内置清单只放已确认可用的少量音色，其余由租户在后台按需追加。
 */
class SmartEditVoiceService
{
    private const TYPE = 'smart_edit';
    private const NAME = 'voices';

    /** 单个组最多同时选几个配音演员（与组合轮换上限一致） */
    public const MAX_VOICES = 50;

    /** 试听文本，与设计稿的短句长度接近 */
    private const PREVIEW_TEXT = '这是一段配音试听，用来确认音色效果。';

    /**
     * 内置音色清单。
     *
     * 豆包语音合成 2.0 的音色 ID 统一形如 zh_xxx_xxx_uranus_bigtts，
     * 必须配 X-Api-Resource-Id: seed-tts-2.0，配 1.0 的 *_moon_bigtts 会报资源不匹配。
     * 授权按账号走，换账号可用范围会变，所以这里只放几个常用音色，
     * 其余由租户在后台「配音音色」里按控制台音色库自行追加。
     *
     * @return array<string, array{label:string,provider_asset_id:string,gender:string,scene:string}>
     */
    public static function builtin(): array
    {
        return [
            'vv' => [
                'label' => 'Vivi',
                'provider_asset_id' => 'zh_female_vv_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'cancan' => [
                'label' => '灿灿',
                'provider_asset_id' => 'zh_female_cancan_uranus_bigtts',
                'gender' => 'female',
                'scene' => '通用场景',
            ],
            'liufei' => [
                'label' => '刘飞',
                'provider_asset_id' => 'zh_male_liufei_uranus_bigtts',
                'gender' => 'male',
                'scene' => '通用场景',
            ],
            'yuanbo' => [
                'label' => '渊博小叔',
                'provider_asset_id' => 'zh_male_yuanboxiaoshu_uranus_bigtts',
                'gender' => 'male',
                'scene' => '角色扮演',
            ],
            'meilinvyou' => [
                'label' => '魅力女友',
                'provider_asset_id' => 'zh_female_meilinvyou_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'tianmeitaozi' => [
                'label' => '甜美桃子',
                'provider_asset_id' => 'zh_female_tianmeitaozi_uranus_bigtts',
                'gender' => 'female',
                'scene' => '角色扮演',
            ],
            'naiqimengwa' => [
                'label' => '奶气萌娃',
                'provider_asset_id' => 'zh_male_naiqimengwa_uranus_bigtts',
                'gender' => 'male',
                'scene' => '角色扮演',
            ],
            'wennuanahu' => [
                'label' => '温暖阿虎',
                'provider_asset_id' => 'zh_male_wennuanahu_uranus_bigtts',
                'gender' => 'male',
                'scene' => '角色扮演',
            ],
        ];
    }

    /**
     * 租户自定义音色（后台追加），与内置清单合并。
     *
     * @return array<int, array{key:string,label:string,provider_asset_id:string,gender:string,scene:string,custom:bool}>
     */
    public static function catalog(int $tenantId = 0): array
    {
        $rows = [];
        foreach (self::builtin() as $key => $item) {
            $rows[$key] = $item + ['key' => $key, 'custom' => false];
        }
        if ($tenantId > 0) {
            foreach (self::custom($tenantId) as $item) {
                $key = (string)$item['key'];
                if ($key === '') {
                    continue;
                }
                // 自定义覆盖内置：同一个 key 以租户配置为准
                $rows[$key] = $item + ['custom' => true];
            }
        }
        return array_values($rows);
    }

    /**
     * 前端下拉用的音色列表。
     *
     * @param int  $tenantId      租户 ID；0 表示只看内置清单
     * @param bool $withAssetId   是否返回 provider_asset_id。租户后台的音色管理要编辑它，
     *                            用户端下拉只需要显示名，不必暴露底层 ID。
     */
    public static function options(int $tenantId = 0, bool $withAssetId = false): array
    {
        $result = [];
        foreach (self::catalog($tenantId) as $item) {
            $row = [
                'key' => (string)$item['key'],
                'label' => (string)$item['label'],
                'gender' => (string)$item['gender'],
                'scene' => (string)$item['scene'],
                'custom' => (bool)$item['custom'],
            ];
            if ($withAssetId) {
                $row['provider_asset_id'] = (string)$item['provider_asset_id'];
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * 取单个音色；key 不存在时抛错，避免提交了拿不到 speaker。
     *
     * @throws Exception
     */
    public static function get(int $tenantId, string $key): array
    {
        foreach (self::catalog($tenantId) as $item) {
            if ((string)$item['key'] === $key) {
                return $item;
            }
        }
        throw new Exception('不支持的配音音色：' . $key);
    }

    /**
     * 音色 key 白名单校验，返回去重后的 key 列表。
     *
     * @param mixed $raw 前端传入的 key 数组
     * @return array<int, string>
     * @throws Exception
     */
    public static function normalizeKeys(int $tenantId, $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $keys = [];
        foreach ($raw as $value) {
            $key = trim((string)$value);
            if ($key === '') {
                continue;
            }
            // 逐项校验：非法 key 直接报错，不要静默丢掉导致口播少了音色
            self::get($tenantId, $key);
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        if (count($keys) > self::MAX_VOICES) {
            throw new Exception('最多选择 ' . self::MAX_VOICES . ' 个配音演员');
        }
        return $keys;
    }

    /** 试听文本 */
    public static function previewText(): string
    {
        return self::PREVIEW_TEXT;
    }

    /**
     * 租户自定义音色清单。
     *
     * @return array<int, array{key:string,label:string,provider_asset_id:string,gender:string,scene:string}>
     */
    public static function custom(int $tenantId): array
    {
        $raw = TenantConfig::where([
            'tenant_id' => $tenantId,
            'type' => self::TYPE,
            'name' => self::NAME,
        ])->value('value');

        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string)($item['key'] ?? ''));
            $assetId = trim((string)($item['provider_asset_id'] ?? ''));
            if ($key === '' || $assetId === '') {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => trim((string)($item['label'] ?? $key)),
                'provider_asset_id' => $assetId,
                'gender' => trim((string)($item['gender'] ?? '')),
                'scene' => trim((string)($item['scene'] ?? '自定义')),
            ];
        }
        return $rows;
    }

    /**
     * 保存租户自定义音色清单。
     *
     * @param array $rows [{key,label,provider_asset_id,gender,scene}, ...]
     */
    public static function saveCustom(int $tenantId, array $rows): void
    {
        $clean = [];
        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string)($item['key'] ?? ''));
            $assetId = trim((string)($item['provider_asset_id'] ?? ''));
            if ($key === '' || $assetId === '') {
                continue;
            }
            $clean[] = [
                'key' => $key,
                'label' => trim((string)($item['label'] ?? $key)),
                'provider_asset_id' => $assetId,
                'gender' => trim((string)($item['gender'] ?? '')),
                'scene' => trim((string)($item['scene'] ?? '自定义')),
            ];
        }

        $payload = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $row = TenantConfig::where([
            'tenant_id' => $tenantId,
            'type' => self::TYPE,
            'name' => self::NAME,
        ])->findOrEmpty();

        if ($row->isEmpty()) {
            TenantConfig::create([
                'tenant_id' => $tenantId,
                'type' => self::TYPE,
                'name' => self::NAME,
                'value' => $payload,
            ]);
            return;
        }
        $row->save(['value' => $payload]);
    }
}
