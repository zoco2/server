<?php

namespace app\api\controller\smart_edit;

use app\api\controller\BaseApiController;
use app\api\lists\smart_edit\SmartEditTaskLists;
use app\common\service\smart_edit\SmartEditDubbingService;
use app\common\service\smart_edit\SmartEditFontService;
use app\common\service\smart_edit\SmartEditService;
use app\common\service\smart_edit\SmartEditVoiceService;
use Exception;

/**
 * 用户端：我的剪辑任务。
 */
class TaskController extends BaseApiController
{
    public function lists()
    {
        return $this->dataLists(new SmartEditTaskLists());
    }

    /**
     * 标题可选字体。读固定预置清单，只返回元数据；提交时再拼 FontURL。
     */
    public function fonts()
    {
        return $this->success('获取成功', SmartEditFontService::options());
    }

    /**
     * 选配音弹窗目录：tabs（豆包2.0 / 克隆 / scene）+ voices。
     */
    public function voices()
    {
        return $this->success(
            '获取成功',
            SmartEditVoiceService::pickerCatalog((int)$this->request->tenantId)
        );
    }

    /**
     * 试听一个音色，返回可直接播放的地址。
     */
    public function voicePreview()
    {
        try {
            $result = SmartEditDubbingService::preview(
                (int)$this->request->tenantId,
                (string)$this->request->post('voice_key', ''),
                (string)$this->request->post('text', '')
            );
            return $this->success('合成成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', SmartEditService::detail(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->get('id', 0)
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function cancel()
    {
        try {
            SmartEditService::cancel(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->post('id', 0)
            );
            return $this->success('已取消', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            SmartEditService::delete(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->post('id', 0)
            );
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}