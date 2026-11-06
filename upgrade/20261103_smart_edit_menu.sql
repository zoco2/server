-- 智能剪辑（租户后台菜单）。
-- 只给 template（tenant_id=0）插入；已有租户在后台「角色权限 → 菜单同步」或新建租户时继承。

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,0,'M','智能剪辑','el-icon-VideoPlay',88,'','smart-edit','','','',0,1,0,'','core','core_tenant_smart_edit',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` item
    WHERE item.`tenant_id`=0 AND item.`source_menu_key`='core_tenant_smart_edit'
);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,parent.`id`,'C','剪辑任务','',10,'smart_edit.admin_task/lists','task','smart_edit/task','','',0,1,0,'','core','core_tenant_smart_edit_task',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`tenant_id`=0 AND parent.`source_menu_key`='core_tenant_smart_edit'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` item
    WHERE item.`tenant_id`=0 AND item.`source_menu_key`='core_tenant_smart_edit_task'
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,parent.`id`,'C','阿里云配置','',20,'smart_edit.config/detail','config','smart_edit/config','','',0,1,0,'','core','core_tenant_smart_edit_config',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`tenant_id`=0 AND parent.`source_menu_key`='core_tenant_smart_edit'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` item
    WHERE item.`tenant_id`=0 AND item.`source_menu_key`='core_tenant_smart_edit_config'
  );