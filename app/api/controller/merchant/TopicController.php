<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\lists\merchant\TopicLists;
use app\api\validate\merchant\TopicValidate;
use app\common\model\merchant\MerchantTopic;
use app\common\service\DefaultWorkGroup;

/** 商家话题库。 */
class TopicController extends BaseApiController
{
    public function lists()
    {
        return $this->dataLists(new TopicLists());
    }

    public function add()
    {
        $name = $this->request->post('name');
        // 库中只保存名称，允许粘贴带 # 前缀的话题。
        $name = is_string($name) ? trim(preg_replace('/^[#＃\s]+/u', '', $name) ?? '') : '';
        $params = (new TopicValidate())->post()->goCheck(null, ['name' => $name]);

        $groupId = filter_var($this->request->post('work_group_id', 0, null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($groupId === false) {
            return $this->fail('成片组无效');
        }
        $tenantId = (int)$this->request->tenantId;
        $create = fn() => MerchantTopic::create([
            'user_id' => $this->userId,
            'name' => $params['name'],
        ]);
        try {
            if ($groupId > 0 && ($tenantId <= 0 || $tenantId !== (int)($this->userInfo['tenant_id'] ?? 0))) {
                return $this->fail('账号与租户不匹配，请重新登录');
            }
            $topic = $groupId === 0 ? $create() : DefaultWorkGroup::createTextItem([
                'tenant_id' => $tenantId,
                'user_id' => $this->userId,
                'type' => 'topic',
            ], $groupId, $create);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->data([
            'id' => (int)$topic->id,
            'name' => $topic->name,
            'create_time' => (int)$topic->getData('create_time'),
        ]);
    }
}
