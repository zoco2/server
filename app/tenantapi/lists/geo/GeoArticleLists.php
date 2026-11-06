<?php

namespace app\tenantapi\lists\geo;

use app\common\model\geo\GeoArticle;
use app\tenantapi\lists\BaseAdminDataLists;
use app\common\lists\ListsSearchInterface;
use app\common\lists\ListsSortInterface;

/**
 * GEO 稿件列表（后台视角，可看全部用户稿件）。
 */
class GeoArticleLists extends BaseAdminDataLists implements ListsSearchInterface, ListsSortInterface
{
    public function setSearch(): array
    {
        return [
            '%like%' => ['title', 'digest'],
            '=' => ['status', 'user_id', 'source'],
        ];
    }

    public function setSortFields(): array
    {
        return ['id' => 'id', 'create_time' => 'create_time', 'update_time' => 'update_time'];
    }

    public function setDefaultOrder(): array
    {
        return ['id' => 'desc'];
    }

    public function lists(): array
    {
        $rows = GeoArticle::where($this->searchWhere)
            ->field(GeoArticle::LIST_FIELDS)
            ->limit($this->limitOffset, $this->limitLength)
            ->order($this->sortOrder)
            ->select()
            ->toArray();
        foreach ($rows as &$row) {
            $row['status_text'] = GeoArticle::STATUS_TEXT[$row['status']] ?? $row['status'];
        }
        unset($row);
        return $rows;
    }

    public function count(): int
    {
        return (int)GeoArticle::where($this->searchWhere)->count();
    }

    public function extend()
    {
        return [
            'status_options' => GeoArticle::STATUS_TEXT,
        ];
    }
}