<?php

declare(strict_types=1);

namespace app\common\model\merchant;

use app\common\model\BaseModel;

/**
 * 平台统一生成的 NFC 码牌库存，第一阶段只提供生成和查询。
 *
 * 沿用 BaseModel 的模型连接和查询入口，在平台请求上下文中访问统一库存。
 * 第一阶段的表没有租户归属字段，按公共基类现有流程查询，无需修改租户分表规则。
 *
 * @property int $id 库存记录主键，与面向商家使用的六位 code 不同
 * @property string $code 六位大写字母与数字混合编号，数据库唯一
 * @property string $batch_no 一次生成请求共用的批次号，用于查找整批码牌
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
}
