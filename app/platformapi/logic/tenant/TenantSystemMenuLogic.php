<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------
namespace app\platformapi\logic\tenant;

use app\common\model\auth\TenantSystemMenu;
use app\common\model\tenant\Tenant;
use think\facade\Db;

/**
 * 租户菜单逻辑
 *  Class TenantSystemMenuLogic
 * @package app\platformapi\logic\tenant
 */
class TenantSystemMenuLogic
{

    public static function templateTenantId(): int
    {
        $value = env('tenant.menu_template_id', 0);
        $id = is_bool($value) ? false : filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($id === false) {
            throw new \RuntimeException('TENANT.MENU_TEMPLATE_ID 必须是非负整数');
        }
        return $id;
    }

    /**
     * 初始化租户菜单。
     */
    public static function initialization($tenant_id, ?int $sourceId = null, string $targetSn = '')
    {
        //初始化租户菜单字段
        $field = "id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,tenant_id";
        $sourceId ??= self::templateTenantId();
        if ($sourceId > 0) {
            $source = Tenant::query()->where('id', $sourceId)->find();
            if (!$source || $sourceId === (int)$tenant_id) {
                throw new \RuntimeException('菜单模板租户不存在或与目标租户相同');
            }
            $sourceTable = (int)$source['tactics'] === 1
                ? 'tenant_system_menu_' . $source['sn']
                : 'tenant_system_menu';
            $targetTable = $targetSn === '' ? 'tenant_system_menu' : 'tenant_system_menu_' . $targetSn;
            Db::transaction(function () use ($sourceId, $tenant_id, $sourceTable, $targetTable, $targetSn, $field) {
                $menus = Db::name($sourceTable)->where('tenant_id', $sourceId)->field($field)->order('id')->select()->toArray();
                if (!$menus) {
                    throw new \RuntimeException('菜单模板租户没有可复制的菜单');
                }
                if ($targetSn !== '') {
                    Db::name($targetTable)->where('tenant_id', $tenant_id)->delete();
                }
                $idMap = [];
                foreach ($menus as $menu) {
                    $oldId = $menu['id'];
                    unset($menu['id']);
                    $menu['tenant_id'] = $tenant_id;
                    $idMap[$oldId] = Db::name($targetTable)->insertGetId($menu);
                }
                foreach ($menus as $menu) {
                    if ($menu['pid'] != 0) {
                        if (!isset($idMap[$menu['pid']])) {
                            throw new \RuntimeException('菜单模板租户存在缺少上级的菜单');
                        }
                        Db::name($targetTable)->where('tenant_id', $tenant_id)->where('id', $idMap[$menu['id']])
                            ->update(['pid' => $idMap[$menu['pid']]]);
                    }
                }
            });
            return;
        }
        //查询模板菜单配置文件 此处默认为租户号为0的模板数据
        $tenantSystemMenuList = TenantSystemMenu::where(['tenant_id' => 0])->field($field)->order('pid')->select()->toArray();
        //记录对应的关系
        $tenantSystemMenu = [];
        foreach ($tenantSystemMenuList as $item) {
            if ($item['pid'] != 0 && !isset($tenantSystemMenu[$item['pid']])) {
                continue;
            }
            $tenantSystemMenu[$item['id']] = $item;
        }
        //创建菜单数据
        foreach ($tenantSystemMenuList as $item) {
            if (!isset($tenantSystemMenu[$item['id']])) {
                continue;
            }
            $item['tenant_id'] = $tenant_id;
            //创建新的菜单并保存原本id对应现在的哪个信息
            $oldId = $item['id'];
            unset($item['id']);
            $newMenu = TenantSystemMenu::create($item);
            $tenantSystemMenu[$oldId] = $newMenu;
        }
        //获取当前租户的初始化菜单关系
        $tenantSystemMenuNewList = TenantSystemMenu::where(['tenant_id' => $tenant_id])->field($field)->order('pid')->select()->toArray();
        //更新对应的主菜单关系
        foreach ($tenantSystemMenuNewList as $item) {
            if ($item['pid'] != 0) {
                if (!isset($tenantSystemMenu[$item['pid']])) {
                    TenantSystemMenu::destroy($item['id']);
                    continue;
                }
                $item['pid'] = $tenantSystemMenu[$item['pid']]['id'];
            }
            $where = array('id' => intval($item['id']));
            TenantSystemMenu::update($item, $where);
        }
    }
}
