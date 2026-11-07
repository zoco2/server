<?php

namespace app\api\controller\smart_edit;

use app\api\controller\BaseApiController;
use app\common\service\smart_edit\SmartEditGroupService;
use Exception;
use think\facade\Log;
use Throwable;

/**
 * 用户端：镜头组。
 *
 * 组是「批量建任务」的容器：前端只编排镜头，组合由后端算。
 * 单任务的 /smart_edit.generate、/smart_edit.task/* 保持不变。
 */
class GroupController extends BaseApiController
{
    /**
     * 建组。校验镜头与素材后落组行，展开与提交都在消费端完成。
     */
    public function create()
    {
        try {
            $result = SmartEditGroupService::create(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            );
            return $this->success('提交成功', $result);
        } catch (Throwable $e) {
            if (!$e instanceof Exception) {
                Log::write('[smart_edit] 建组异常：' . $e->getMessage());
                return $this->fail('创建剪辑任务失败，请稍后重试');
            }
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 组列表，每行附成片进度。
     */
    public function lists()
    {
        try {
            $result = SmartEditGroupService::lists(
                (int)$this->request->tenantId,
                $this->userId,
                [
                    'page_no' => (int)$this->request->get('page_no', 1),
                    'page_size' => (int)$this->request->get('page_size', 20),
                    'status' => (string)$this->request->get('status', ''),
                ]
            );
            return $this->success('获取成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 组详情 + 子任务分页。
     */
    public function detail()
    {
        try {
            $result = SmartEditGroupService::detail(
                (int)$this->request->tenantId,
                $this->userId,
                (string)$this->request->get('batch_no', ''),
                (int)$this->request->get('page_no', 1),
                (int)$this->request->get('page_size', 20)
            );
            return $this->success('获取成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 删除组，连带软删组下所有任务。
     */
    public function delete()
    {
        try {
            SmartEditGroupService::delete(
                (int)$this->request->tenantId,
                $this->userId,
                (string)$this->request->post('batch_no', '')
            );
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
