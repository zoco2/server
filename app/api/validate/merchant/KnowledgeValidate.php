<?php

namespace app\api\validate\merchant;

use app\common\validate\BaseValidate;

/**
 * 品牌知识库校验。
 */
class KnowledgeValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|integer|gt:0',
        'brand_id' => 'require|integer|gt:0',
        'folder_id' => 'integer|egt:0',
        'name' => 'require|length:1,60',
        'title' => 'require|length:1,120',
        'content_html' => 'require|checkContentHtml',
        'summary' => 'max:500',
        'sort' => 'integer',
        'keyword' => 'max:80',
        'metadata_json' => 'checkMetadataJson',
        'tags_json' => 'checkTagsJson',
    ];

    protected $message = [
        'id.require' => '请选择记录',
        'brand_id.require' => '请选择品牌',
        'name.require' => '请填写文件夹名称',
        'name.length' => '文件夹名称不能超过60个字',
        'title.require' => '请填写文章标题',
        'title.length' => '文章标题不能超过120个字',
        'content_html.require' => '请填写文章内容',
        'summary.max' => '摘要不能超过500个字',
        'keyword.max' => '搜索关键词不能超过80个字',
    ];

    public function sceneFolders()
    {
        return $this->only(['brand_id']);
    }

    public function sceneAddFolder()
    {
        return $this->only(['brand_id', 'name', 'sort', 'metadata_json']);
    }

    public function sceneEditFolder()
    {
        return $this->only(['id', 'name', 'sort', 'metadata_json']);
    }

    public function sceneDeleteFolder()
    {
        return $this->only(['id']);
    }

    public function sceneArticles()
    {
        return $this->only(['brand_id', 'folder_id', 'keyword']);
    }

    public function sceneDetail()
    {
        return $this->only(['id']);
    }

    public function sceneAddArticle()
    {
        return $this->only(['brand_id', 'folder_id', 'title', 'content_html', 'summary', 'sort', 'metadata_json', 'tags_json']);
    }

    public function sceneEditArticle()
    {
        return $this->only(['id', 'folder_id', 'title', 'content_html', 'summary', 'sort', 'metadata_json', 'tags_json']);
    }

    public function sceneDeleteArticle()
    {
        return $this->only(['id']);
    }

    /**
     * @param mixed $value
     * @return bool|string
     */
    protected function checkContentHtml($value)
    {
        if (!is_string($value)) {
            return '文章内容格式无效';
        }
        if (mb_strlen($value) > 200000) {
            return '文章内容不能超过200000个字';
        }
        $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') {
            return '请填写文章内容';
        }
        return true;
    }

    /**
     * @param mixed $value
     * @return bool|string
     */
    protected function checkMetadataJson($value)
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_string($value)) {
            return '元数据格式无效';
        }
        $decoded = json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($decoded)) {
            return '元数据必须是JSON对象';
        }
        return true;
    }

    /**
     * @param array $value
     * @return bool
     */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param mixed $value
     * @return bool|string
     */
    protected function checkTagsJson($value)
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_string($value)) {
            return '标签格式无效';
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || !self::isList($decoded)) {
            return '标签必须是JSON数组';
        }
        return true;
    }
}
