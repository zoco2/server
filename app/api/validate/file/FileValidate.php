<?php

namespace app\api\validate\file;

use app\common\validate\BaseValidate;

/**
 * 用户文件分组校验。
 */
class FileValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'cid' => 'require|integer|egt:0',
        'ids' => 'require|array|checkIds',
        'type' => 'require|in:10,20,30,40',
        'pid' => 'require|integer|egt:0',
        'name' => 'require|max:20',
    ];

    protected $message = [
        'id.require' => '请选择分组',
        'id.integer' => '分组参数无效',
        'id.gt' => '分组参数无效',
        'cid.require' => '请选择分组',
        'cid.integer' => '分组参数无效',
        'cid.egt' => '分组参数无效',
        'ids.require' => '请选择文件',
        'ids.array' => '文件参数无效',
        'type.require' => '请选择分组类型',
        'type.in' => '分组类型无效',
        'pid.require' => '请选择父分组',
        'pid.integer' => '父分组参数无效',
        'pid.egt' => '父分组参数无效',
        'name.require' => '请填写分组名称',
        'name.max' => '分组名称长度须为20字符内',
    ];

    /**
     * @return FileValidate
     */
    public function sceneListCate()
    {
        return $this->only(['type']);
    }

    /**
     * @return FileValidate
     */
    public function sceneAddCate()
    {
        return $this->only(['type', 'pid', 'name']);
    }

    /**
     * @return FileValidate
     */
    public function sceneEditCate()
    {
        return $this->only(['id', 'name']);
    }

    /**
     * @return FileValidate
     */
    public function sceneDelCate()
    {
        return $this->only(['id']);
    }

    /**
     * @return FileValidate
     */
    public function sceneMove()
    {
        return $this->only(['ids', 'cid']);
    }

    /**
     * @param mixed $value
     * @return bool|string
     */
    protected function checkIds($value)
    {
        if (!is_array($value) || $value === []) {
            return '请选择文件';
        }
        foreach ($value as $id) {
            if (!is_numeric($id) || (int)$id <= 0) {
                return '文件参数无效';
            }
        }
        return true;
    }
}
