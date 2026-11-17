<?php

declare(strict_types=1);

namespace app\common\model\merchant;

use app\common\model\BaseModel;
use app\common\enum\AdminTerminalEnum;

/**
 * 平台统一生成的 NFC 码牌库存，支持查询及分配给指定租户。
 *
 * 沿用 BaseModel 的模型连接和查询入口，在平台请求上下文中访问统一库存。
 * 平台端访问统一库存；其他终端继续沿用公共基类的租户作用域，不修改核心规则。
 * 码牌记录租户归属，预留 store_id 用于门店绑定，不在库存中重复保存用户 ID。
 *
 * @property int $id 库存记录主键，与面向商家使用的六位 code 不同
 * @property string $code 六位大写字母与数字混合编号，数据库唯一
 * @property string $batch_no 一次生成请求共用的批次号，用于查找整批码牌
 * @property int|null $tenant_id 目标租户 ID，NULL 表示未分配的平台库存
 * @property int|null $store_id 绑定的门店 ID，NULL 表示未绑定，一个门店可关联多块码牌
 * @property int|null $tenant_assigned_admin_id 执行分配的平台管理员 ID
 * @property int|null $tenant_assigned_at 分配给租户的时间，Unix 秒
 * @property int $created_admin_id 发起生成的平台管理员 ID
 * @property int $create_time 生成时间，Unix 秒
 * @property int $update_time 最近更新时间，Unix 秒
 */
final class NfcCode extends BaseModel
{
    /** 由 ORM 自动添加数据库配置中的表前缀。 */
    protected $name = 'merchant_nfc_code';

    /** 服务统一写入时间戳，关闭模型的自动时间处理，列表保留整数秒。 */
    protected $autoWriteTimestamp = false;

    /**
     * 平台库存操作不能受“分配目标租户”限制。
     *
     * 公共中间件会把请求 tenant_id 解析到 request()->tenantId，即使来源是平台端。
     * 平台需要查询所有租户及未分配库存，因此仅在本 NFC 模型的平台查询中跳过租户范围；
     * 否则分配更新会同时要求 tenant_id 等于目标租户且为空，造成零行更新。
     * 租户端和用户端仍调用父类，保留现有租户隔离及分表行为。
     *
     * @param \think\db\Query $query 模型查询对象，沿用继承的 tenantId 全局作用域入口
     */
    public static function scopeTenantId($query): void
    {
        if (AdminTerminalEnum::isPlatform()) {
            return;
        }
        parent::scopeTenantId($query);
    }
}
