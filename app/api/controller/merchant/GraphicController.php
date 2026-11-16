<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\lists\merchant\GraphicLists;
use app\common\model\merchant\MerchantGraphic;

class GraphicController extends BaseApiController
{
    public function lists()
    {
        return $this->dataLists(new GraphicLists());
    }

    public function add()
    {
        $name = $this->request->post('name', '', null);
        $content = $this->request->post('content', '', null);
        $fileIds = $this->request->post('file_ids', [], null);
        $name = is_string($name) ? trim($name) : '';
        $content = is_string($content) ? trim($content) : '';
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            return $this->fail('请输入 1 到 100 字的图文标题');
        }
        if ($content === '' || mb_strlen($content, 'UTF-8') > 20000) {
            return $this->fail('请输入 1 到 20000 字的图文正文');
        }
        if (!is_array($fileIds) || !array_is_list($fileIds) || $fileIds === []
            || count($fileIds) > MerchantGraphic::MAX_IMAGES) {
            return $this->fail('请添加 1 到 9 张图片');
        }
        $ids = [];
        foreach ($fileIds as $value) {
            $id = (is_int($value) || is_string($value))
                ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($id === false || isset($ids[$id])) {
                return $this->fail('图片编号无效或重复，请重新添加');
            }
            $ids[$id] = $id;
        }

        $tenantId = (int)$this->request->tenantId;
        $fileIds = array_values($ids);
        $files = MerchantGraphic::imageFiles($tenantId, $this->userId, $fileIds);
        if (count($files) !== count($fileIds)) {
            return $this->fail('部分图片不可用，请重新添加');
        }
        $graphic = MerchantGraphic::create([
            'tenant_id' => $tenantId,
            'user_id' => $this->userId,
            'name' => $name,
            'content' => $content,
            'file_ids' => $fileIds,
        ]);
        return $this->data(MerchantGraphic::present([
            'id' => (int)$graphic->id,
            'name' => $name,
            'content' => $content,
            'file_ids' => $fileIds,
            'create_time' => (int)$graphic->getData('create_time'),
            'update_time' => (int)$graphic->getData('update_time'),
        ], $files));
    }
}
