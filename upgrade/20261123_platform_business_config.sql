-- 登记平台基础配置及其保存权限。
INSERT INTO `la_system_menu`
    (pid,type,name,icon,sort,perms,paths,component,is_show,source,source_menu_key,create_time,update_time)
SELECT root.id,'C','基础配置','el-icon-Setting',105,'setting.business_config/detail','base-config',
    'setting/base-config',1,'core','core_platform_business_config',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` root
WHERE root.pid=0 AND root.type='M' AND root.paths='setting'
  AND NOT EXISTS (
      SELECT 1 FROM `la_system_menu` page WHERE page.source_menu_key='core_platform_business_config'
  );

INSERT INTO `la_system_menu`
    (pid,type,name,perms,is_show,source,source_menu_key,create_time,update_time)
SELECT page.id,'A','保存配置','setting.business_config/save',0,'core','core_platform_business_config_save',
    UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` page
WHERE page.source_menu_key='core_platform_business_config'
  AND NOT EXISTS (
      SELECT 1 FROM `la_system_menu` action WHERE action.source_menu_key='core_platform_business_config_save'
  );
