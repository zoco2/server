<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\lists\merchant\CopyLists;
use app\common\model\merchant\MerchantCopy;

class CopyController extends BaseApiController
{
    public function lists()
    {
        return $this->dataLists(new CopyLists());
    }

    public function add()
    {
        $name = $this->request->post('name', '', null);
        $content = $this->request->post('content', '', null);
        $name = is_string($name) ? trim($name) : '';
        $content = is_string($content) ? trim($content) : '';
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            return $this->fail('请输入 1 到 100 字的文案标题');
        }
        if ($content === '' || mb_strlen($content, 'UTF-8') > 20000) {
            return $this->fail('请输入 1 到 20000 字的文案正文');
        }

        $copy = MerchantCopy::create([
            'tenant_id' => (int)$this->request->tenantId,
            'user_id' => $this->userId,
            'name' => $name,
            'content' => $content,
        ]);
        return $this->data([
            'id' => (int)$copy->id,
            'name' => $name,
            'content' => $content,
            'create_time' => (int)$copy->getData('create_time'),
            'update_time' => (int)$copy->getData('update_time'),
        ]);
    }
}
