-- likeadmin:tenant-base-config-menus-v1
-- 升级器将相同的 SQL 应用于共享表和已有的分表租户。
-- 旧配置菜单通常已登记读取权限。对只有组件名的旧记录，沿用其 ID 和角色关联。
UPDATE `la_tenant_system_menu` legacy
JOIN (
    SELECT 'geo/config' AS component, 'geo.manuscript/detail' AS permission
    UNION ALL SELECT 'smart_edit/config','smart_edit.config/detail'
    UNION ALL SELECT 'channel/share_setting','channel.share_setting/getConfig'
) section ON section.component=legacy.component
LEFT JOIN `la_tenant_system_menu` defined
    ON defined.tenant_id=legacy.tenant_id AND defined.perms=section.permission
SET legacy.perms=section.permission, legacy.update_time=UNIX_TIMESTAMP()
WHERE legacy.type='C' AND legacy.perms='' AND defined.id IS NULL;

INSERT INTO `la_tenant_system_menu`
    (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT root.tenant_id,root.id,'C','基础配置','el-icon-Setting',105,'','base-config','setting/base-config','','',0,1,0,'','core','core_tenant_base_config',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.pid=0 AND root.type='M' AND root.paths='setting'
  AND NOT EXISTS (
      SELECT 1 FROM `la_tenant_system_menu` page
      WHERE page.tenant_id=root.tenant_id
        AND (page.source_menu_key='core_tenant_base_config' OR (page.pid=root.id AND page.paths='base-config'))
  );

-- 复用当前租户已有的权限记录。新租户的区块权限挂在基础配置下，供角色管理分配。
INSERT INTO `la_tenant_system_menu`
    (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT page.tenant_id,page.id,'A',action.label,'',0,action.permission,'','','','',0,0,0,'','core',action.menu_key,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` page
CROSS JOIN (
    SELECT '查看 GEO 配置' AS label,'geo.manuscript/detail' AS permission,'core_tenant_base_geo_read' AS menu_key
    UNION ALL SELECT '保存 GEO 检测配置','geo.diagnosis_config/save','core_tenant_base_geo_diag_save'
    UNION ALL SELECT '查看剪辑配置','smart_edit.config/detail','core_tenant_base_edit_read'
    UNION ALL SELECT '查看分享配置','channel.share_setting/getConfig','core_tenant_base_share_read'
    UNION ALL SELECT '保存分享配置','channel.share_setting/setConfig','core_tenant_base_share_save'
) action
WHERE page.source_menu_key='core_tenant_base_config'
  AND NOT EXISTS (
      SELECT 1 FROM `la_tenant_system_menu` existing
      WHERE existing.tenant_id=page.tenant_id AND existing.perms=action.permission
  );

-- 只从已授权且尚未迁移的配置菜单继承导航入口，不授予其他区块或保存权限。
INSERT IGNORE INTO `la_tenant_system_role_menu` (role_id,menu_id)
SELECT DISTINCT grant_row.role_id,destination.id
FROM `la_tenant_system_role_menu` grant_row
JOIN `la_tenant_system_menu` legacy ON legacy.id=grant_row.menu_id
JOIN `la_tenant_system_role` assigned_role ON assigned_role.id=grant_row.role_id
    AND assigned_role.tenant_id=legacy.tenant_id
JOIN `la_tenant_system_menu` page ON page.tenant_id=legacy.tenant_id AND page.source_menu_key='core_tenant_base_config'
JOIN `la_tenant_system_menu` destination ON destination.tenant_id=page.tenant_id AND destination.id IN (page.id,page.pid)
WHERE legacy.type='C' AND legacy.is_disable=0 AND legacy.selected<>'/setting/base-config'
  AND (legacy.component,legacy.perms) IN (
      ('geo/config','geo.manuscript/detail'),('smart_edit/config','smart_edit.config/detail'),
      ('channel/share_setting','channel.share_setting/getConfig')
  );

-- 原路由和菜单 ID 保留，用 selected 标记统一入口；角色手动撤销后的授权不会在重跑时恢复。
UPDATE `la_tenant_system_menu` legacy
JOIN `la_tenant_system_menu` page ON page.tenant_id=legacy.tenant_id AND page.source_menu_key='core_tenant_base_config'
SET legacy.is_show=0, legacy.selected='/setting/base-config', legacy.update_time=UNIX_TIMESTAMP()
WHERE legacy.type='C' AND legacy.is_disable=0 AND legacy.selected<>'/setting/base-config'
  AND (legacy.component,legacy.perms) IN (
      ('geo/config','geo.manuscript/detail'),('smart_edit/config','smart_edit.config/detail'),
      ('channel/share_setting','channel.share_setting/getConfig')
  );
