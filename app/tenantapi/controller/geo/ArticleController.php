<?php

namespace app\tenantapi\controller\geo;

use app\common\service\geo\GeoArticleService;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\geo\GeoArticleLists;
use Exception;

/**
 * GEO 稿件（租户后台）。稿件由前台用户撰写，后台负责查看与清理。
 */
class ArticleController extends BaseAdminController
{
    /** 列表 */
    public function lists()
    {
        return $this->dataLists(new GeoArticleLists());
    }

    /** 详情 */
    public function detail()
    {
        try {
            return $this->success('获取成功', GeoArticleService::detail((int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 删除 */
    public function delete()
    {
        try {
            GeoArticleService::delete((int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}