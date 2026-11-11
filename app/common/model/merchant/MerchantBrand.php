<?php

namespace app\common\model\merchant;

use app\common\model\BaseModel;
use DomainException;
use think\facade\Db;
use think\model\concern\SoftDelete;

/**
 * 商家品牌。
 */
class MerchantBrand extends BaseModel
{
    use SoftDelete;

    public const PROFILE_FIELDS = [
        'name', 'industry', 'contact_name', 'contact_phone', 'contact_email', 'website',
        'address', 'logo_url', 'cover_url',
    ];

    public const SECTION_LABELS = [
        'company_intro' => '企业介绍',
        'products_services' => '产品与服务',
        'target_customers' => '目标客户与地区',
        'competitor_facts' => '竞品与差异事实',
    ];

    public static function profileFields(array $params): array
    {
        $fields = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $fields[$field] = trim((string)($params[$field] ?? ''));
        }
        return $fields;
    }

    public static function deleteForOwner(int $tenantId, int $userId, int $brandId): void
    {
        $owner = ['tenant_id' => $tenantId, 'user_id' => $userId];
        Db::transaction(static function () use ($owner, $brandId): void {
            // 门店保存也会锁住所属品牌，避免删除时新增关联门店。
            $brand = self::where($owner)->where('id', $brandId)->lock(true)->findOrEmpty();
            if ($brand->isEmpty()) {
                throw new DomainException('品牌不存在或无权限');
            }

            $store = MerchantStore::where($owner)->where('brand_id', $brandId)
                ->field('id')->lock(true)->findOrEmpty();
            if (!$store->isEmpty()) {
                throw new DomainException('请先删除该品牌下的门店');
            }

            if (!$brand->delete()) {
                throw new DomainException('品牌删除失败');
            }
        });
    }

    protected $name = 'merchant_brand';

    protected $deleteTime = 'delete_time';
}
