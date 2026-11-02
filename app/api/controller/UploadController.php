<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------

namespace app\api\controller;

use app\api\logic\file\FileLogic;
use app\common\enum\FileEnum;
use app\common\service\UploadService;
use Exception;
use think\response\Json;


/** 上传文件
 * Class UploadController
 * @package app\api\controller
 */
class UploadController extends BaseApiController
{

    /**
     * @notes 上传图片
     * @return Json
     * @author 段誉
     * @date 2022/9/20 18:11
     */
    public function image()
    {
        try {
            $this->syncTenantContext();
            $cid = FileLogic::uploadCid(FileEnum::IMAGE_TYPE, $this->request->post('cid', 0));
            $result = UploadService::image($cid, $this->userId, FileEnum::SOURCE_USER);
            return $this->success('上传成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function file()
    {
        try {
            $this->syncTenantContext();
            $cid = FileLogic::uploadCid(FileEnum::FILE_TYPE, $this->request->post('cid', 0));
            $result = UploadService::file($cid, $this->userId, FileEnum::SOURCE_USER);
            return $this->success('上传成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function video()
    {
        try {
            $this->syncTenantContext();
            $cid = FileLogic::uploadCid(FileEnum::VIDEO_TYPE, $this->request->post('cid', 0));
            $result = UploadService::video($cid, $this->userId, FileEnum::SOURCE_USER);
            return $this->success('上传成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function syncTenantContext(): void
    {
        if ((int)($this->request->tenantId ?? 0) > 0) {
            return;
        }
        if ((int)($this->userInfo['tenant_id'] ?? 0) > 0) {
            $this->request->tenantId = (int)$this->userInfo['tenant_id'];
        }
    }
}
