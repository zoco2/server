<?php

namespace app\api\controller\geo;

use app\api\controller\BaseApiController;
use app\common\service\geo\GeoMediaCatalogService;
use Exception;

/**
 * GEO 媒体资源广场（PC 前台用户）。
 *
 * 只暴露售价，不返回海讯成本价和内部镜像字段（见 GeoMediaCatalogService）。
 * 资源广场是公开内容，免登录可浏览；下单时才校验登录。
 */
class MediaController extends BaseApiController
{
    /** 免登录接口 */
    public array $notNeedLogin = ['types', 'lists', 'detail'];

    /** 资源类型字典 + 各类型在售数量与价格区间 */
    public function types()
    {
        try {
            return $this->success('获取成功', GeoMediaCatalogService::types());
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 资源广场分页。
     * resource_type / region / keyword / newsresource / searchcollint / weekendpublish
     * min_price / max_price / sort(price_asc|price_desc|recommend) / page_no / page_size
     */
    public function lists()
    {
        try {
            return $this->success('获取成功', GeoMediaCatalogService::lists($this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 资源详情 */
    public function detail()
    {
        try {
            return $this->success('获取成功', GeoMediaCatalogService::detail((int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}