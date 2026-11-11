<?php

namespace app\api\logic\merchant;

use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantBrand;
use DomainException;
use Throwable;

/**
 * 品牌保存与查询。
 */
class BrandLogic extends BaseLogic
{
    /**
     * @return array
     */
    public static function choices(): array
    {
        return MerchantScope::query(MerchantBrand::class)
            ->field('id,name')
            ->order('id', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * @param int $id
     * @return array
     */
    public static function detail(int $id): array
    {
        $row = MerchantScope::query(MerchantBrand::class)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('品牌不存在或无权限');
            return [];
        }
        $data = $row->toArray();
        foreach (array_keys(MerchantBrand::SECTION_LABELS) as $field) {
            $data[$field] = (string)($data[$field] ?? '');
        }
        return $data;
    }

    /**
     * @param array $params
     * @return bool
     */
    public static function edit(array $params): bool
    {
        $id = (int)$params['id'];
        $row = MerchantScope::query(MerchantBrand::class)->where('id', $id)->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('品牌不存在或无权限');
            return false;
        }
        if (self::nameTaken($params['name'], $id)) {
            self::setError('品牌名称已存在');
            return false;
        }
        try {
            $row->save(MerchantBrand::profileFields($params));
            return true;
        } catch (Throwable $e) {
            self::setError(self::duplicate($e, '品牌名称已存在') ? '品牌名称已存在' : '品牌保存失败');
            return false;
        }
    }

    /**
     * 只更新一段资料正文，不改基本信息。
     * @param array $params
     * @return bool
     */
    public static function saveSection(array $params): bool
    {
        $section = (string)$params['section'];
        if (!isset(MerchantBrand::SECTION_LABELS[$section])) {
            self::setError('资料段落无效');
            return false;
        }
        $row = MerchantScope::query(MerchantBrand::class)->where('id', (int)$params['id'])->findOrEmpty();
        if ($row->isEmpty()) {
            self::setError('品牌不存在或无权限');
            return false;
        }
        if (!array_key_exists('content', $params)) {
            self::setError('请填写内容');
            return false;
        }
        $content = trim((string)$params['content']);
        if (mb_strlen($content) > 5000) {
            self::setError(MerchantBrand::SECTION_LABELS[$section] . '不能超过5000个字');
            return false;
        }
        try {
            $row->save([$section => $content]);
            return true;
        } catch (Throwable $e) {
            self::setError('资料保存失败');
            return false;
        }
    }

    /**
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        try {
            MerchantBrand::deleteForOwner((int)request()->tenantId, (int)request()->userId, $id);
            return true;
        } catch (DomainException $e) {
            self::setError($e->getMessage());
        } catch (Throwable $e) {
            self::setError('品牌删除失败');
        }
        return false;
    }

    /**
     * @param string $name
     * @param int $exceptId
     * @return bool
     */
    private static function nameTaken(string $name, int $exceptId = 0): bool
    {
        $query = MerchantScope::query(MerchantBrand::class)->where('name', trim($name));
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        return $query->count() > 0;
    }

    /**
     * @param Throwable $e
     * @param string $needle
     * @return bool
     */
    private static function duplicate(Throwable $e, string $needle): bool
    {
        return str_contains($e->getMessage(), 'uk_owner_name') || str_contains($e->getMessage(), $needle);
    }
}
