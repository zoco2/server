<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\lists\merchant\BrandLists;
use app\api\logic\merchant\BrandLogic;
use app\api\validate\merchant\BrandValidate;

/**
 * 商家品牌。
 */
class BrandController extends BaseApiController
{
    /**
     * @notes 品牌列表
     * @return \think\response\Json
     */
    public function lists()
    {
        return $this->dataLists(new BrandLists());
    }

    /**
     * @notes 品牌下拉
     * @return \think\response\Json
     */
    public function choices()
    {
        return $this->data(BrandLogic::choices());
    }

    /**
     * @notes 品牌详情
     * @return \think\response\Json
     */
    public function detail()
    {
        $params = (new BrandValidate())->get()->goCheck('detail');
        $result = BrandLogic::detail((int)$params['id']);
        if ($result === [] && BrandLogic::hasError()) {
            return $this->fail(BrandLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @notes 创建品牌
     * @return \think\response\Json
     */
    public function add()
    {
        $params = (new BrandValidate())->post()->goCheck('add');
        if (!BrandLogic::add($params)) {
            return $this->fail(BrandLogic::getError());
        }
        return $this->success('创建成功', [], 1, 1);
    }

    /**
     * @notes 编辑品牌
     * @return \think\response\Json
     */
    public function edit()
    {
        $params = (new BrandValidate())->post()->goCheck('edit');
        if (!BrandLogic::edit($params)) {
            return $this->fail(BrandLogic::getError());
        }
        return $this->success('保存成功', [], 1, 1);
    }

    /**
     * @notes 删除品牌
     * @return \think\response\Json
     */
    public function delete()
    {
        $params = (new BrandValidate())->post()->goCheck('delete');
        if (!BrandLogic::delete((int)$params['id'])) {
            return $this->fail(BrandLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }
}
