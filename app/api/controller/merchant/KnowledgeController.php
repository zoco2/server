<?php

namespace app\api\controller\merchant;

use app\api\controller\BaseApiController;
use app\api\lists\merchant\KnowledgeArticleLists;
use app\api\logic\merchant\KnowledgeLogic;
use app\api\validate\merchant\KnowledgeValidate;

/**
 * 品牌知识库。
 */
class KnowledgeController extends BaseApiController
{
    /**
     * @notes 文件夹列表
     */
    public function folders()
    {
        $params = (new KnowledgeValidate())->get()->goCheck('folders');
        $result = KnowledgeLogic::folders((int)$params['brand_id']);
        if ($result === [] && KnowledgeLogic::hasError()) {
            return $this->fail(KnowledgeLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @notes 创建文件夹
     */
    public function addFolder()
    {
        $params = (new KnowledgeValidate())->post()->goCheck('addFolder');
        if (!KnowledgeLogic::addFolder($params)) {
            return $this->fail(KnowledgeLogic::getError());
        }
        return $this->success('创建成功', [], 1, 1);
    }

    /**
     * @notes 编辑文件夹
     */
    public function editFolder()
    {
        $params = (new KnowledgeValidate())->post()->goCheck('editFolder');
        if (!KnowledgeLogic::editFolder($params)) {
            return $this->fail(KnowledgeLogic::getError());
        }
        return $this->success('保存成功', [], 1, 1);
    }

    /**
     * @notes 删除文件夹
     */
    public function deleteFolder()
    {
        $params = (new KnowledgeValidate())->post()->goCheck('deleteFolder');
        if (!KnowledgeLogic::deleteFolder((int)$params['id'])) {
            return $this->fail(KnowledgeLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }

    /**
     * @notes 文章列表
     */
    public function articles()
    {
        $params = (new KnowledgeValidate())->get()->goCheck('articles');
        if (!KnowledgeLogic::canUseBrand((int)$params['brand_id'])) {
            return $this->fail(KnowledgeLogic::getError());
        }
        return $this->dataLists(new KnowledgeArticleLists());
    }

    /**
     * @notes 文章详情
     */
    public function detail()
    {
        $params = (new KnowledgeValidate())->get()->goCheck('detail');
        $result = KnowledgeLogic::detail((int)$params['id']);
        if ($result === [] && KnowledgeLogic::hasError()) {
            return $this->fail(KnowledgeLogic::getError());
        }
        return $this->data($result);
    }

    /**
     * @notes 创建文章
     */
    public function addArticle()
    {
        $params = (new KnowledgeValidate())->post()->goCheck('addArticle');
        if (!KnowledgeLogic::addArticle($params)) {
            return $this->fail(KnowledgeLogic::getError());
        }
        return $this->success('创建成功', [], 1, 1);
    }

    /**
     * @notes 编辑文章
     */
    public function editArticle()
    {
        $params = (new KnowledgeValidate())->post()->goCheck('editArticle');
        if (!KnowledgeLogic::editArticle($params)) {
            return $this->fail(KnowledgeLogic::getError());
        }
        return $this->success('保存成功', [], 1, 1);
    }

    /**
     * @notes 删除文章
     */
    public function deleteArticle()
    {
        $params = (new KnowledgeValidate())->post()->goCheck('deleteArticle');
        if (!KnowledgeLogic::deleteArticle((int)$params['id'])) {
            return $this->fail(KnowledgeLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }
}
