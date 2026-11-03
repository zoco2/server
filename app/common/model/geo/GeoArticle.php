<?php

namespace app\common\model\geo;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

/**
 * GEO 稿件。前台用户撰写，投稿时以预览地址（服务端直出 HTML）提交给海讯社。
 *
 * content 存富文本 HTML，图片用相对路径（uploads/...）落库，预览/详情读取时再补域名。
 *
 * @property string $title
 * @property string $content
 * @property string $status
 * @property string $preview_token
 * @property string $preview_url
 */
class GeoArticle extends BaseModel
{
    use SoftDelete;

    protected $name = 'geo_article';
    protected $deleteTime = 'delete_time';
    protected $defaultSoftDelete = null;
    // 表里时间字段是 int 时间戳，交给模型自动写入，避免手写的时间戳被覆盖或漏写。
    protected $autoWriteTimestamp = 'int';

    /** 稿件状态：草稿 / 可投稿 / 已提交 / 已发布 / 被拒 */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_TEXT = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_READY => '待投稿',
        self::STATUS_SUBMITTED => '已投稿',
        self::STATUS_PUBLISHED => '已发布',
        self::STATUS_REJECTED => '被拒稿',
    ];

    /** 列表/详情输出字段，不含正文 */
    public const LIST_FIELDS = 'id,user_id,title,digest,source_question,source,preview_url,status,publish_count,remark,create_time,update_time';
}