<?php
namespace app\api\controller\app\aigc_micro_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
use app\common\service\app\aigc_micro_video\MicroVideoCatalogVersion;
class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail','revision'];
    public function revision()
    {
        try { return $this->success('操作成功', ['revision'=>MicroVideoCatalogVersion::current((int)$this->request->tenantId)]); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function detail()
    {
        try { $config=MicroVideoSettings::detail((int)$this->request->tenantId, false);return $this->success("操作成功", $this->request->get('compact')?MicroVideoSettings::compact($config):$config); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
}
