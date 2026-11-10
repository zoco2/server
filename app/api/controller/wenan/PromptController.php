<?php

namespace app\api\controller\wenan;

use app\api\controller\BaseApiController;
use app\api\logic\merchant\KnowledgeLogic;
use app\api\logic\merchant\MerchantScope;
use app\common\model\merchant\MerchantBrand;
use app\common\service\wenan\WenanEnhanceService;
use Exception;
use Throwable;

/**
 * 企业知识库 / 智能剪辑文案 LLM 接口。
 */
class PromptController extends BaseApiController
{
    /**
     * 美化文案。
     */
    public function beautify()
    {
        return $this->run('beautify', '美化成功');
    }

    /**
     * 优化文案。
     */
    public function optimize()
    {
        return $this->run('optimize', '优化成功');
    }

    /**
     * 强化文案。
     */
    public function enhance()
    {
        return $this->run('enhance', '强化成功');
    }

    /**
     * 帮写文案。
     */
    public function write()
    {
        return $this->run('write', '帮写成功');
    }

    /**
     * 统一改写（body.mode = beautify|optimize|enhance|write）。
     */
    public function rewrite()
    {
        return $this->run(null, '处理成功');
    }

    /**
     * 可选模式与场景说明，供前端下拉使用。
     */
    public function options()
    {
        return $this->success('获取成功', [
            'modes' => WenanEnhanceService::modeOptions(),
            'scenes' => WenanEnhanceService::sceneOptions(),
        ]);
    }

    /**
     * @param string|null $forceMode 固定模式；null 时读请求 mode
     */
    private function run(?string $forceMode, string $successMessage)
    {
        try {
            $params = $this->request->post();
            if (!is_array($params)) {
                $params = [];
            }
            if ($forceMode !== null) {
                $params['mode'] = $forceMode;
            }
            $params = $this->withBrandContext($params);
            return $this->success(
                $successMessage,
                WenanEnhanceService::rewrite(
                    (int)$this->request->tenantId,
                    $this->userId,
                    $params
                )
            );
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            return $this->fail('文案处理暂不可用，请稍后重试');
        }
    }

    /**
     * 校验品牌权限，并补齐 brand_name 供模型参考。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function withBrandContext(array $params): array
    {
        $brandId = (int)($params['brand_id'] ?? 0);
        if ($brandId <= 0) {
            return $params;
        }
        if (!KnowledgeLogic::canUseBrand($brandId)) {
            throw new Exception(KnowledgeLogic::getError() ?: '品牌不存在或无权限');
        }
        if (trim((string)($params['brand_name'] ?? '')) !== '') {
            return $params;
        }
        $row = MerchantScope::query(MerchantBrand::class)
            ->where('id', $brandId)
            ->field('id,name')
            ->findOrEmpty();
        if (!$row->isEmpty()) {
            $params['brand_name'] = (string)$row['name'];
        }
        return $params;
    }
}
