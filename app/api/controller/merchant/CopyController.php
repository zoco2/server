<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\lists\merchant\CopyLists;
use app\common\model\merchant\MerchantCopy;
use app\common\service\DefaultWorkGroup;

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

        $groupId = filter_var($this->request->post('work_group_id', 0, null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($groupId === false) {
            return $this->fail('成片组无效');
        }
        $tenantId = (int)$this->request->tenantId;
        $create = fn() => MerchantCopy::create([
            'tenant_id' => (int)$this->request->tenantId,
            'user_id' => $this->userId,
            'name' => $name,
            'content' => $content,
        ]);
        if ($groupId > 0 && $tenantId <= 0) {
            return $this->fail('缺少租户上下文，请重新登录');
        }
        try {
            $copy = $groupId === 0 ? $create() : DefaultWorkGroup::createTextItem([
                'tenant_id' => $tenantId,
                'user_id' => $this->userId,
                'type' => 'copy',
            ], $groupId, $create);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->data([
            'id' => (int)$copy->id,
            'name' => $name,
            'content' => $content,
            'create_time' => (int)$copy->getData('create_time'),
            'update_time' => (int)$copy->getData('update_time'),
        ]);
    }
}
