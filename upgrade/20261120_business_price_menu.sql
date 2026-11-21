-- likeadmin:tenant-base-config-menus-v1
INSERT INTO `la_tenant_system_menu`
    (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT page.tenant_id,page.id,'A',action.label,'',0,action.permission,'','','','',0,0,0,'','core',action.menu_key,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` page
CROSS JOIN (
    SELECT '查看业务价格' AS label,'setting.business_price/detail' AS permission,'core_tenant_price_read' AS menu_key
    UNION ALL SELECT '保存业务价格','setting.business_price/save','core_tenant_price_save'
) action
WHERE page.source_menu_key='core_tenant_base_config'
  AND NOT EXISTS (
      SELECT 1 FROM `la_tenant_system_menu` existing
      WHERE existing.tenant_id=page.tenant_id AND existing.perms=action.permission
  );
