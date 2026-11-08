<?php

namespace app\api\controller\sticker;

use app\api\controller\BaseApiController;
use app\common\service\sticker\StickerService;
use Exception;

/**
 * 用户端：贴纸库。供智能剪辑选择贴纸使用。
 */
class LibraryController extends BaseApiController
{
    /**
     * 分类列表。
     */
    public function categories()
    {
        try {
            return $this->success('获取成功', StickerService::categories());
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 资源列表。category_code 不传或空表示不限分类。
     */
    public function resources()
    {
        try {
            $result = StickerService::resources(
                (string)$this->request->get('category_code', ''),
                (int)$this->request->get('page_no', 1),
                (int)$this->request->get('page_size', 50)
            );
            return $this->success('获取成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
