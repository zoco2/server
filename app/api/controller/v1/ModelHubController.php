<?php

declare(strict_types=1);

namespace app\api\controller\v1;

use app\api\controller\BaseApiController;
use app\common\service\model_hub\ModelHubRuntimeService;
use InvalidArgumentException;
use RuntimeException;
use think\response\Json;

final class ModelHubController extends BaseApiController
{
    public function chatTest(): Json
    {
        if (!$this->request->isPost()) {
            return $this->fail('仅支持 POST 请求');
        }
        if ((int)($this->request->userId ?? 0) <= 0) {
            return $this->fail('请先登录');
        }

        // 模拟回复仍使用测试租户的算力余额，只有配置的租户可以调用。
        $testTenantId = (int)env('model_hub.test_tenant_id', 0);
        if ($testTenantId <= 0 || (int)($this->request->tenantId ?? 0) !== $testTenantId) {
            return $this->fail('当前租户未开启 Model Hub 测试');
        }

        $requestId = $this->request->post('request_id');
        $prompt = $this->request->post('prompt');
        if (!is_string($requestId) || !preg_match('/\A[a-zA-Z0-9._:-]{1,64}\z/', $requestId)) {
            return $this->fail('request_id 必须是 1 至 64 位字母、数字或 . _ : -');
        }
        if (!is_string($prompt) || trim($prompt) === '' || mb_strlen($prompt) > 2000) {
            return $this->fail('请填写 1 至 2000 字的提示词');
        }

        try {
            $call = ModelHubRuntimeService::run('chat-test:' . $requestId, [
                'capability' => 'chat',
                'model' => 'model-hub-demo',
                'estimated_units' => 10,
                'parameters' => ['prompt' => trim($prompt)],
            ], static fn(string $capability, string $model, array $parameters): array => [
                'status' => 'success',
                'usage_units' => 3,
                'result' => ['reply' => '模拟回复：' . $parameters['prompt']],
            ]);
        } catch (InvalidArgumentException | RuntimeException $error) {
            return $this->fail($error->getMessage());
        }

        return $this->data([
            'call_id' => (int)$call['id'],
            'request_id' => $requestId,
            'model' => (string)$call['model'],
            'mock' => true,
            'status' => (string)$call['status'],
            'reason' => (string)$call['reason'],
            'estimated_units' => (int)$call['estimated_units'],
            'actual_units' => $call['actual_units'] === null ? null : (int)$call['actual_units'],
            'reserved_points' => number_format((float)$call['reserved_points'], 2, '.', ''),
            'charged_points' => number_format((float)$call['charged_points'], 2, '.', ''),
            'reply' => $call['result']['reply'] ?? '',
        ]);
    }
}
