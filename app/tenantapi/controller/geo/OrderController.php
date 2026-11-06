<?php

namespace app\tenantapi\controller\geo;

use app\common\model\geo\GeoOrder;
use app\common\service\geo\GeoOrderService;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\geo\GeoOrderLists;
use Exception;

/**
 * GEO 投稿单（租户后台）。
 *
 * 运营查看所有投稿单、手动重试推送、查询状态、确认/反馈、导出。
 */
class OrderController extends BaseAdminController
{
    /** 投稿单列表 */
    public function lists()
    {
        return $this->dataLists(new GeoOrderLists());
    }

    /** 投稿单详情 */
    public function detail()
    {
        try {
            return $this->success('获取成功', GeoOrderService::detail((int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 手动推送/重试推送（状态 -1 或 2 时）。
     *
     * POST /tenantapi/geo.order/push {id}
     */
    public function push()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            $order = GeoOrder::where('id', $id)->findOrEmpty();
            if ($order->isEmpty()) {
                return $this->fail('投稿单不存在');
            }
            GeoOrderService::push($order);
            return $this->success('推送成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 手动查询单个订单状态。
     *
     * POST /tenantapi/geo.order/sync {id}
     */
    public function sync()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            $order = GeoOrder::where('id', $id)->findOrEmpty();
            if ($order->isEmpty()) {
                return $this->fail('投稿单不存在');
            }
            GeoOrderService::sync($order);
            return $this->success('查询成功', GeoOrderService::detail($id), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 批量查询到期订单状态（手动触发定时任务）。
     *
     * POST /tenantapi/geo.order/syncDue {limit?}
     */
    public function syncDue()
    {
        try {
            $limit = min(500, max(1, (int)$this->request->post('limit', 100)));
            $stat = GeoOrderService::syncDue($limit);
            return $this->success('同步完成', $stat, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 确认发布（flag=5）。
     *
     * POST /tenantapi/geo.order/confirm {id}
     */
    public function confirm()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            $order = GeoOrder::where('id', $id)->findOrEmpty();
            if ($order->isEmpty()) {
                return $this->fail('投稿单不存在');
            }
            GeoOrderService::process($order, 5);
            return $this->success('确认成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 提交修改意见（flag=4）。
     *
     * POST /tenantapi/geo.order/feedback {id, feedback}
     */
    public function feedback()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            $feedback = mb_substr(trim((string)$this->request->post('feedback', '')), 0, 500);
            if ($feedback === '') {
                return $this->fail('请填写反馈意见');
            }
            $order = GeoOrder::where('id', $id)->findOrEmpty();
            if ($order->isEmpty()) {
                return $this->fail('投稿单不存在');
            }
            GeoOrderService::process($order, 4, $feedback);
            return $this->success('反馈已提交', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
