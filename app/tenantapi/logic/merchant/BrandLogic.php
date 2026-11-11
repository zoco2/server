<?php

namespace app\tenantapi\logic\merchant;

use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantBrandKnowledgeFolder;
use app\common\model\user\User;
use DomainException;
use think\facade\Db;
use Throwable;

class BrandLogic extends BaseLogic
{
    private const DEFAULT_KNOWLEDGE_FOLDERS = [
        '企业事实',
        '品牌介绍',
        '门店信息',
        '产品与服务',
        '客户与市场',
        '竞品与差异事实',
    ];

    public static function add(array $params, int $tenantId): ?array
    {
        $owner = ['tenant_id' => $tenantId, 'user_id' => (int)$params['user_id']];
        $fields = MerchantBrand::profileFields($params);

        try {
            return Db::transaction(static function () use ($owner, $fields): ?array {
                // 同一用户的品牌创建必须串行执行。
                $user = User::where('tenant_id', $owner['tenant_id'])
                    ->where('id', $owner['user_id'])
                    ->lock(true)
                    ->findOrEmpty();
                if ($user->isEmpty()) {
                    self::setError('所属用户不存在或无权限');
                    return null;
                }
                if (!MerchantBrand::where($owner)->field('id')->lock(true)->findOrEmpty()->isEmpty()) {
                    self::setError('该用户已有品牌，每位用户只能拥有一个品牌');
                    return null;
                }

                $brand = MerchantBrand::create(
                    $owner + $fields + array_fill_keys(array_keys(MerchantBrand::SECTION_LABELS), '')
                );
                $sort = count(self::DEFAULT_KNOWLEDGE_FOLDERS);
                foreach (self::DEFAULT_KNOWLEDGE_FOLDERS as $name) {
                    MerchantBrandKnowledgeFolder::create($owner + [
                        'brand_id' => (int)$brand->id,
                        'name' => $name,
                        'sort' => $sort--,
                        'metadata_json' => '{}',
                    ]);
                }
                return [
                    'id' => (int)$brand->id,
                    'name' => $fields['name'],
                    'industry' => $fields['industry'],
                ];
            });
        } catch (Throwable $e) {
            self::setError(str_contains($e->getMessage(), 'uk_owner_name')
                ? '该用户已有品牌，每位用户只能拥有一个品牌'
                : '品牌保存失败');
            return null;
        }
    }

    public static function delete(int $tenantId, int $userId, int $brandId): bool
    {
        try {
            MerchantBrand::deleteForOwner($tenantId, $userId, $brandId);
            return true;
        } catch (DomainException $e) {
            self::setError($e->getMessage());
        } catch (Throwable $e) {
            self::setError('品牌删除失败');
        }
        return false;
    }
}
