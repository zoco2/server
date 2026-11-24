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

        try {
            $topic = DefaultWorkGroup::createTextItem('topic', $this->userId, fn() =>
                MerchantTopic::create(['user_id' => $this->userId, 'name' => $params['name']])
            );
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
