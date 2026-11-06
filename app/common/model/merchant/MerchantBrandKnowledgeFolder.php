<?php

namespace app\common\model\merchant;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

/**
 * 品牌知识库文件夹。
 */
class MerchantBrandKnowledgeFolder extends BaseModel
{
    use SoftDelete;

    protected $name = 'merchant_brand_knowledge_folder';

    protected $deleteTime = 'delete_time';
}
