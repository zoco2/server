<?php

namespace app\api\controller\geo;

use app\api\controller\BaseApiController;
use app\common\service\geo\GeoOrderService;
use Exception;

/**
 * GEO 投稿单（PC 前台用户）。
 *
 * 用户在 PC 端选媒体 + 稿件下单，查看订单状态，确认发布或反馈修改意见。
 */
class OrderController extends BaseApiController
{
    /**
     * 创建投稿单并推送海讯社。
     *
     * POST /api/geo.order/create
     * {
     *   article_id: int,
     *   media_id: int,
     *   price_type: int,
     *   remark?: string,
     *   origin?: string
     * }
     */
    public function create()
    {
        try {
            $data = GeoOrderService::create($this->userId, $this->request->post());
            return $this->success('投稿成功', $data, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 我的投稿单列表。
     *
     * GET /api/geo.order/lists?status=&keyword=&page_no=&page_size=
     */
    public function lists()
    {
        try {
            return $this->success('获取成功', GeoOrderService::lists($this->request->get(), $this->userId));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 投稿单详情（含状态日志）。
     *
     * GET /api/geo.order/detail?id=
     */
    public function detail()
    {
        try {
            return $this->success('获取成功', GeoOrderService::detail((int)$this->request->get('id', 0), $this->userId));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 确认发布（海讯 flag=5）。
     *
     * POST /api/geo.order/confirm {id}
     */
    public function confirm()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            $order = \app\common\model\geo\GeoOrder::where('id', $id)->findOrEmpty();
            if ($order->isEmpty() || (int)$order['user_id'] !== $this->userId) {
                return $this->fail('投稿单不存在');
            }
            GeoOrderService::process($order, 5);
            return $this->success('确认成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 提交修改意见（海讯 flag=4）。
     *
     * POST /api/geo.order/feedback {id, feedback}
     */
    public function feedback()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            $feedback = mb_substr(trim((string)$this->request->post('feedback', '')), 0, 500);
            if ($feedback === '') {
                return $this->fail('请填写反馈意见');
            }
            $order = \app\common\model\geo\GeoOrder::where('id', $id)->findOrEmpty();
            if ($order->isEmpty() || (int)$order['user_id'] !== $this->userId) {
                return $this->fail('投稿单不存在');
            }
            GeoOrderService::process($order, 4, $feedback);
            return $this->success('反馈已提交', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
