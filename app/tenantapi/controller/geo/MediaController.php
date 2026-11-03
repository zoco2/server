<?php

namespace app\tenantapi\controller\geo;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\geo\GeoMediaLists;
use app\tenantapi\logic\geo\GeoMediaLogic;
use Exception;

/**
 * GEO 媒体资源（租户后台）。
 */
class MediaController extends BaseAdminController
{
    /** 列表 */
    public function lists()
    {
        return $this->dataLists(new GeoMediaLists());
    }

    /** 类型字典与数量统计 */
    public function types()
    {
        return $this->success('获取成功', [
            'types' => GeoMediaLogic::types(),
            'markup' => GeoMediaLogic::markup($this->tenantId),
        ]);
    }

    /** 手动同步某类型 */
    public function sync()
    {
        try {
            return $this->success('同步完成', GeoMediaLogic::sync($this->tenantId, (int)$this->request->post('type', 0)), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 设置售价 / 本地隐藏 */
    public function update()
    {
        try {
            return $this->success('保存成功', GeoMediaLogic::update($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 校正上下架状态 */
    public function refreshStates()
    {
        try {
            $ids = (array)$this->request->post('ids', []);
            return $this->success('校正完成', GeoMediaLogic::refreshStates($this->tenantId, $ids), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 保存加成倍率 */
    public function saveMarkup()
    {
        try {
            return $this->success('保存成功', ['markup' => GeoMediaLogic::saveMarkup($this->tenantId, $this->request->post())], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}