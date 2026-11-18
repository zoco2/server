<?php
namespace app\common\service\app\aigc_micro_video;

use think\facade\Db;

/** A lightweight change signal; never exposes market rows, costs or credentials. */
final class MicroVideoCatalogVersion
{
    public static function current(int $tenant): string
    {
        // Hash values as well as revisions: two edits in the same second must not be missed.
        $rules=Db::name('power_market_price_rule')->whereIn('owner_id',array_values(array_unique([0,$tenant])))->order('id')->select()->toArray();
        $skus=Db::name('power_market_sku')->field('id,product_id,upstream_price,status,sale_status,update_time')->order('id')->select()->toArray();
        $products=Db::name('power_market_product')->field('id,status,update_time')->order('id')->select()->toArray();
        $availability=Db::name('tenant_power_market_sku_price')->where('tenant_id',$tenant)->field('sku_id,sale_status,update_time')->order('sku_id')->select()->toArray();
        return hash('sha256',MicroVideoStore::encode([$tenant,$rules,$skus,$products,$availability,MicroVideoSettings::get($tenant)]));
    }
}
