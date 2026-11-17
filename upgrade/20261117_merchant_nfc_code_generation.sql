-- 第一阶段：平台统一生成 NFC 码牌库存。
CREATE TABLE IF NOT EXISTS `la_merchant_nfc_code` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` char(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `batch_no` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_admin_id` int unsigned NOT NULL,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_merchant_nfc_code` (`code`),
  KEY `idx_batch` (`batch_no`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商家 NFC 码牌';

INSERT INTO `la_system_menu`
  (pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT p.id,'C','NFC 码牌','el-icon-Tickets',91,'merchant.nfc_code/lists','nfc-code','tenant/nfc-code/index','','',0,1,0,'','core','core_platform_merchant_nfc_code',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` p
WHERE p.pid=0 AND p.type='M' AND p.paths='tenant'
  AND NOT EXISTS (SELECT 1 FROM `la_system_menu` e WHERE e.source_menu_key='core_platform_merchant_nfc_code');

INSERT INTO `la_system_menu`
  (pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT p.id,'A','批量生成','',0,'merchant.nfc_code/generate','','','','',0,0,0,'','core','core_platform_merchant_nfc_code_generate',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` p
WHERE p.source_menu_key='core_platform_merchant_nfc_code'
  AND NOT EXISTS (SELECT 1 FROM `la_system_menu` e WHERE e.perms='merchant.nfc_code/generate');
