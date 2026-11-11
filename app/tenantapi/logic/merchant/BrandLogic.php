<?php

namespace app\tenantapi\logic\merchant;

use app\common\logic\BaseLogic;
use app\common\model\merchant\MerchantBrand;
use app\common\model\merchant\MerchantBrandKnowledgeFolder;
use app\common\model\user\User;
use think\facade\Db;
use Throwable;

class BrandLogic extends BaseLogic
{
    private const DEFAULT_KNOWLEDGE_FOLDERS = [
        '企业事实',
        '品牌介绍',
        '产品与服务',
        '客户与市场',
        '竞品与差异事实',
        '门店信息',
    ];

    public static function add(array $params, int $tenantId): bool
    {
        $owner = ['tenant_id' => $tenantId, 'user_id' => (int)$params['user_id']];
        $fields = MerchantBrand::profileFields($params);

        try {
            return Db::transaction(static function () use ($owner, $fields): bool {
                // 锁住所属用户，避免为同一用户并发创建同名品牌。
                $user = User::where('tenant_id', $owner['tenant_id'])
                    ->where('id', $owner['user_id'])
                    ->lock(true)
                    ->findOrEmpty();
                if ($user->isEmpty()) {
                    self::setError('所属用户不存在或无权限');
                    return false;
                }
                if (MerchantBrand::where($owner)->where('name', $fields['name'])->count() > 0) {
                    self::setError('品牌名称已存在');
                    return false;
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
                return true;
            });
        } catch (Throwable $e) {
            self::setError(str_contains($e->getMessage(), 'uk_owner_name') ? '品牌名称已存在' : '品牌保存失败');
            return false;
        }
    }
}
