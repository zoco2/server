<?php

namespace app\common\service\wenan;

use Exception;
use app\common\model\TenantConfig;
use app\common\service\power\MarketTextModelRuntimeService;

/**
 * 文案 LLM 模型配置（企业知识库 + 智能剪辑等共用）。
 *
 * 存在 la_tenant_config（type=wenan, name=model），不挂在 smart_edit 名下。
 */
class WenanConfigService
{
    private const TYPE = 'wenan';

    private const NAME = 'model';

    /**
     * 读取原始配置。
     *
     * @return array{text_model_id:string}
     */
    public static function get(int $tenantId): array
    {
        $raw = TenantConfig::where([
            'tenant_id' => $tenantId,
            'type' => self::TYPE,
            'name' => self::NAME,
        ])->value('value');

        // 兼容短暂误存到 smart_edit/wenan 的配置，读到后仍以本表为准写入
        if (!is_string($raw) || $raw === '') {
            $legacy = TenantConfig::where([
                'tenant_id' => $tenantId,
                'type' => 'smart_edit',
                'name' => 'wenan',
            ])->value('value');
            if (is_string($legacy) && $legacy !== '') {
                $raw = $legacy;
            }
        }

        $data = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return [
            'text_model_id' => trim((string)($data['text_model_id'] ?? '')),
        ];
    }

    /**
     * 前端回显：模型 id + 可选项。
     *
     * @return array{text_model_id:string,model_options:array<int,array{id:string,name:string,model_code:string}>}
     */
    public static function forDisplay(int $tenantId): array
    {
        $config = self::get($tenantId);
        return [
            'text_model_id' => $config['text_model_id'],
            'model_options' => self::modelOptions($tenantId),
        ];
    }

    /**
     * @return array<int, array{id:string,name:string,model_code:string}>
     */
    public static function modelOptions(int $tenantId): array
    {
        return array_map(static function (array $model): array {
            return [
                'id' => (string)$model['id'],
                'name' => (string)$model['name'],
                'model_code' => (string)$model['model_code'],
            ];
        }, MarketTextModelRuntimeService::textOptions($tenantId));
    }

    /**
     * 保存文案模型；空值表示走算力市场默认第一个模型。
     *
     * @throws Exception
     */
    public static function save(int $tenantId, array $params): void
    {
        $selection = trim((string)($params['text_model_id'] ?? ''));
        if ($selection !== '') {
            if (!preg_match('/^[1-9][0-9]*$/D', $selection)) {
                throw new Exception('请选择有效的文案模型');
            }
            try {
                MarketTextModelRuntimeService::resolveModel($tenantId, $selection);
            } catch (Exception $e) {
                throw new Exception('所选文案模型未上架或已不可用：' . $e->getMessage());
            }
        }

        $payload = json_encode([
            'text_model_id' => $selection,
        ], JSON_UNESCAPED_UNICODE);

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

    /**
     * 文案改写调用时解析模型；空配置返回空串，由调用方走默认路由。
     */
    public static function resolveTextModelId(int $tenantId): string
    {
        $selection = self::get($tenantId)['text_model_id'];
        if ($selection === '') {
            return '';
        }
        try {
            MarketTextModelRuntimeService::resolveModel($tenantId, $selection);
            return $selection;
        } catch (Exception) {
            throw new Exception('文案模型不可用，请到租户后台「文案模型」重新选择');
        }
    }
}
