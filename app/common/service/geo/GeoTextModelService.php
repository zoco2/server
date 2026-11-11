<?php

namespace app\common\service\geo;

use app\common\service\power\MarketTextModelProviderException;
use app\common\service\power\MarketTextModelRuntimeService;
use app\common\service\power\TextModelCapacity;
use app\common\service\wenan\WenanConfigService;
use Exception;
use InvalidArgumentException;

class GeoTextModelService
{
    private const MAX_DEFAULT_CANDIDATES = 4;

    public static function candidates(int $tenantId, bool $requiresTools): array
    {
        $selection = WenanConfigService::get($tenantId)['text_model_id'];
        if ($selection !== '') {
            try {
                $model = MarketTextModelRuntimeService::resolveModel($tenantId, $selection);
            } catch (Exception $e) {
                throw new InvalidArgumentException('文案模型不可用，请到租户后台「文案模型」重新选择', 0, $e);
            }
            if ($requiresTools && (!MarketTextModelRuntimeService::supportsToolProtocol($model) || self::toolSupport($model) === false)) {
                throw new InvalidArgumentException('当前文案模型不支持知识库工具调用，请到租户后台重新选择文案模型');
            }
            return [$model];
        }

        $options = MarketTextModelRuntimeService::textOptions($tenantId);
        if (!$requiresTools && $options !== []) {
            return array_slice($options, 0, self::MAX_DEFAULT_CANDIDATES);
        }

        $supported = [];
        $undeclared = [];
        foreach ($options as $model) {
            if (!MarketTextModelRuntimeService::supportsToolProtocol($model)) {
                continue;
            }
            $support = self::toolSupport($model);
            if ($support === true) {
                $supported[] = $model;
            } elseif ($support === null) {
                $undeclared[] = $model;
            }
        }
        $candidates = array_slice([...$supported, ...$undeclared], 0, self::MAX_DEFAULT_CANDIDATES);
        if ($candidates === []) {
            throw new InvalidArgumentException($requiresTools
                ? '暂无可用于知识库工具调用的文本模型，请在租户后台配置文案模型'
                : '暂无可用的文本模型，请在租户后台配置文案模型');
        }
        return $candidates;
    }

    /**
     * @param non-empty-list<array> $candidates
     * @return array{0: array, 1: array} 模型结果及实际使用的模型
     */
    public static function generate(int $tenantId, int $userId, array $params, array $candidates): array
    {
        $inputTokens = MarketTextModelRuntimeService::estimateTokens(json_encode(
            [$params['messages'], $params['system_prompt'], $params['tools'] ?? []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        foreach ($candidates as $index => $model) {
            if ($inputTokens > self::inputBudget($model, $params['max_tokens'])) {
                throw new InvalidArgumentException('企业资料或知识库目录超出当前模型的上下文容量');
            }
            try {
                $result = MarketTextModelRuntimeService::generate($tenantId, $userId, $params + [
                    'model_selection' => (string)$model['id'],
                    '_disable_model_fallback' => true,
                ]);
                return [$result, $model];
            } catch (MarketTextModelProviderException $e) {
                if (!MarketTextModelRuntimeService::isExplicitModelUnavailable($e->getMessage())) {
                    throw $e;
                }
                if (!$e->allowsModelFallback || $e->partialResult !== null || !isset($candidates[$index + 1])) {
                    throw new InvalidArgumentException('上游拒绝了当前文本模型，请检查租户文案模型及渠道配置', 0, $e);
                }
            }
        }
        throw new InvalidArgumentException('暂无可用的 GEO 文本模型');
    }

    private static function toolSupport(array $model): ?bool
    {
        // 能力档案保留未知状态，旧的市场快照可能把未声明的能力压平为 false。
        $fields = array_column($model['capability_profile']['fields'] ?? [], 'value', 'key');
        return $fields['supports_tools'] ?? null;
    }

    private static function inputBudget(array $model, int $outputTokens): int
    {
        $capacity = TextModelCapacity::metadata($model);
        $budget = $capacity['max_input_tokens'] ?: PHP_INT_MAX;
        if ($capacity['context_window'] > 0) {
            $budget = min($budget, max(1, $capacity['context_window'] - TextModelCapacity::output($model, $outputTokens)));
        }
        return $budget === PHP_INT_MAX ? 24000 : $budget;
    }
}
