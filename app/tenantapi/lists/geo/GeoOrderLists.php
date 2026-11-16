<?php

namespace app\tenantapi\lists\geo;

use app\common\model\geo\GeoOrder;
use app\common\service\geo\AigcGeoManuscriptService;
use app\tenantapi\lists\BaseAdminDataLists;

/**
 * GEO 投稿单列表（租户后台）。
 */
class GeoOrderLists extends BaseAdminDataLists
{
    /**
     * 搜索条件。
     */
    public function where(): array
    {
        $where = [];
        if (isset($this->params['status']) && $this->params['status'] !== '') {
            $where[] = ['status', '=', (int)$this->params['status']];
        }
        if (isset($this->params['resource_type']) && $this->params['resource_type'] !== '') {
            $where[] = ['resource_type', '=', (int)$this->params['resource_type']];
        }
        if (isset($this->params['user_id']) && $this->params['user_id'] !== '') {
            $where[] = ['user_id', '=', (int)$this->params['user_id']];
        }
        return $where;
    }

    /**
     * 关键词搜索。
     */
    public function queryKeyword($query)
    {
        $keyword = trim((string)($this->params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('order_no', '%' . $keyword . '%')
                    ->whereOr('title', 'like', '%' . $keyword . '%')
                    ->whereOr('media_name', 'like', '%' . $keyword . '%');
            });
        }
        return $query;
    }

    /**
     * 列表字段。
     */
    public function columns(): array
    {
        return [
            'id',
            'user_id',
            'order_no',
            'article_id',
            'media_name',
            'resource_type',
            'price_type',
            'cost_price',
            'sale_price',
            'title',
            'status',
            'remote_flag',
            'publish_url',
            'fail_reason',
            'retryable',
            'push_attempts',
            'last_push_time',
            'last_query_time',
            'create_time',
            'update_time',
            'finish_time',
            'power_log_id',
        ];
    }

    /**
     * 排序。
     */
    public function orderBy(): array
    {
        return ['id' => 'desc'];
    }

    /**
     * 数据处理。
     */
    public function lists(): array
    {
        $lists = (new GeoOrder())
            ->field($this->columns())
            ->where($this->where())
            ->where($this->queryKeyword($this))
            ->order($this->orderBy())
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();

        foreach ($lists as &$item) {
            $item['status_text'] = GeoOrder::STATUS_TEXT[$item['status']] ?? (string)$item['status'];
            $item['fail_reason'] = AigcGeoManuscriptService::publicMessage((string)$item['fail_reason']);
            $item['can_retry'] = in_array((int)$item['status'], [GeoOrder::STATUS_LOCAL, GeoOrder::STATUS_REJECTED], true)
                && ((int)$item['status'] === GeoOrder::STATUS_LOCAL || (bool)$item['retryable']);
            $item['can_confirm'] = (int)$item['remote_flag'] === GeoOrder::STATUS_PUBLISHED;
        }
        unset($item);

        return $lists;
    }

    /**
     * 总数。
     */
    public function count(): int
    {
        return (new GeoOrder())
            ->where($this->where())
            ->where($this->queryKeyword($this))
            ->count();
    }
}
