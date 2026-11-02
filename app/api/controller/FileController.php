<?php

namespace app\api\controller;

use app\api\lists\file\FileCateLists;
use app\api\logic\file\FileLogic;
use app\api\validate\file\FileValidate;

/**
 * 用户文件分组。
 */
class FileController extends BaseApiController
{
    /**
     * @notes 分组树
     * @return \think\response\Json
     */
    public function listCate()
    {
        (new FileValidate())->get()->goCheck('listCate');
        return $this->dataLists(new FileCateLists());
    }

    /**
     * @notes 新建分组
     * @return \think\response\Json
     */
    public function addCate()
    {
        $params = (new FileValidate())->post()->goCheck('addCate');
        if (!FileLogic::addCate($params)) {
            return $this->fail(FileLogic::getError());
        }
        return $this->success('添加成功', [], 1, 1);
    }

    /**
     * @notes 编辑分组
     * @return \think\response\Json
     */
    public function editCate()
    {
        $params = (new FileValidate())->post()->goCheck('editCate');
        if (!FileLogic::editCate($params)) {
            return $this->fail(FileLogic::getError());
        }
        return $this->success('编辑成功', [], 1, 1);
    }

    /**
     * @notes 删除分组
     * @return \think\response\Json
     */
    public function delCate()
    {
        $params = (new FileValidate())->post()->goCheck('delCate');
        if (!FileLogic::delCate((int)$params['id'])) {
            return $this->fail(FileLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }

    /**
     * @notes 移动文件
     * @return \think\response\Json
     */
    public function move()
    {
        $params = (new FileValidate())->post()->goCheck('move');
        if (!FileLogic::move($params)) {
            return $this->fail(FileLogic::getError());
        }
        return $this->success('移动成功', [], 1, 1);
    }
}
