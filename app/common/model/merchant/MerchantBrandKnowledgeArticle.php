<?php

namespace app\common\model\merchant;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

/**
 * 品牌知识库文章。
 */
class MerchantBrandKnowledgeArticle extends BaseModel
{
    use SoftDelete;

    protected $name = 'merchant_brand_knowledge_article';

    protected $deleteTime = 'delete_time';
}
