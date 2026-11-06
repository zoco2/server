<?php

namespace app\api\controller\geo;

use app\api\controller\BaseApiController;
use app\common\service\geo\GeoDiagnosisService;
use Exception;

/**
 * GEO 品牌诊断（PC 前台用户）。
 */
class DiagnosisController extends BaseApiController
{
    /** 平台目录与价格 */
    public function platforms()
    {
        try {
            return $this->success('获取成功', GeoDiagnosisService::platforms());
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 省份代码目录 */
    public function regions()
    {
        try {
            return $this->success('获取成功', GeoDiagnosisService::regions());
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 服务商余额与价格 */
    public function usage()
    {
        try {
            return $this->success('获取成功', GeoDiagnosisService::usage());
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 发起品牌诊断 */
    public function create()
    {
        try {
            return $this->success('诊断已提交', GeoDiagnosisService::create($this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 我的诊断记录 */
    public function lists()
    {
        try {
            return $this->success('获取成功', GeoDiagnosisService::lists($this->request->get(), $this->userId));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 诊断详情 */
    public function detail()
    {
        try {
            return $this->success('获取成功', GeoDiagnosisService::detail((int)$this->request->get('id', 0), $this->userId));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 手动同步诊断进度 */
    public function sync()
    {
        try {
            return $this->success('同步成功', GeoDiagnosisService::syncOne((int)$this->request->post('id', 0), $this->userId));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
