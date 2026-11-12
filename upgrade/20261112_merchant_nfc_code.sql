-- All NFC codes use the shared table, including codes assigned to split-table tenants.
CREATE TABLE IF NOT EXISTS `la_merchant_nfc_code` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` char(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `batch_no` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `store_id` bigint unsigned DEFAULT NULL,
  `created_admin_id` int unsigned NOT NULL,
  `tenant_assigned_admin_id` int unsigned DEFAULT NULL,
  `tenant_assigned_at` int unsigned DEFAULT NULL,
  `user_assigned_admin_id` int unsigned DEFAULT NULL,
  `user_assigned_at` int unsigned DEFAULT NULL,
  `activated_at` int unsigned DEFAULT NULL,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_nfc_code` (`code`),
  KEY `idx_batch` (`batch_no`, `id`),
  KEY `idx_owner_store` (`tenant_id`, `user_id`, `store_id`, `activated_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商家 NFC 码牌';

INSERT INTO `la_system_menu`
  (pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT p.id,'C','NFC 码牌','el-icon-Tickets',91,'merchant.nfc_code/lists','nfc-code','tenant/nfc-code/index','','',0,1,0,'','core','core_platform_merchant_nfc_code',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` p
WHERE p.pid=0 AND p.type='M' AND p.paths='tenant'
  AND NOT EXISTS (SELECT 1 FROM `la_system_menu` e WHERE e.source_menu_key='core_platform_merchant_nfc_code');

INSERT INTO `la_system_menu`
  (pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT p.id,'A',a.label,'',0,CONCAT('merchant.nfc_code/',a.action_name),'','','','',0,0,0,'','core',CONCAT('core_platform_merchant_nfc_code_',a.action_name),1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` p
CROSS JOIN (SELECT 'generate' AS action_name, '批量生成' AS label UNION ALL SELECT 'assignTenant','分配给租户') a
WHERE p.source_menu_key='core_platform_merchant_nfc_code'
  AND NOT EXISTS (SELECT 1 FROM `la_system_menu` e WHERE e.perms=CONCAT('merchant.nfc_code/',a.action_name));

INSERT INTO `la_tenant_system_menu`
  (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT p.tenant_id,p.id,'C','NFC 码牌','el-icon-Tickets',89,'merchant.nfc_code/lists','nfc-code','finance/nfc-code','','',0,1,0,'','core','core_tenant_merchant_nfc_code',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` p
WHERE p.pid=0 AND p.type='M' AND p.paths='finance'
  AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.source_menu_key='core_tenant_merchant_nfc_code');

INSERT INTO `la_tenant_system_menu`
  (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT p.tenant_id,p.id,'A','分配给用户','',0,'merchant.nfc_code/assignUser','','','','',0,0,0,'','core','core_tenant_merchant_nfc_code_assign_user',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` p
WHERE p.source_menu_key='core_tenant_merchant_nfc_code'
  AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.perms='merchant.nfc_code/assignUser');
