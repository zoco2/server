<?php

namespace app\api\controller\smart_edit;

use app\api\controller\BaseApiController;
use app\common\service\smart_edit\SmartEditService;
use Exception;
use think\facade\Log;
use Throwable;

/**
 * 用户端：提交智能剪辑任务。
 */
class GenerateController extends BaseApiController
{
    public function index()
    {
        try {
            $result = SmartEditService::generate((int)$this->request->tenantId, $this->userId, $this->request->post());
            return $this->success('提交成功', $result);
        } catch (Throwable $e) {
            if (!$e instanceof Exception) {
                Log::write('[smart_edit] 提交异常：' . $e->getMessage());
                return $this->fail('提交剪辑任务失败，请稍后重试');
            }
            return $this->fail($e->getMessage());
        }
    }
}