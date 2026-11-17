-- 第二阶段：平台将已有 NFC 码牌分配给指定租户。
-- 先执行第一阶段建表脚本；默认表前缀 la_，手动执行时按站点配置替换。
-- 逐项检测缺失字段及索引，允许重复执行，并保留全部已有编号和批次。
-- 本阶段不添加 user_id，后续通过门店绑定建立商家关系。

-- NULL 表示仍在平台库存，非空表示归属于该租户。
SET @nfc_alter = IF(
  EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_merchant_nfc_code' AND COLUMN_NAME = 'tenant_id'),
  'SELECT 1',
  'ALTER TABLE `la_merchant_nfc_code` ADD COLUMN `tenant_id` int unsigned DEFAULT NULL COMMENT ''所属租户，NULL 表示未分配'' AFTER `batch_no`'
);
PREPARE nfc_statement FROM @nfc_alter;
EXECUTE nfc_statement;
DEALLOCATE PREPARE nfc_statement;

-- 操作管理员取自登录态，用于追溯本次分配由谁执行。
SET @nfc_alter = IF(
  EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_merchant_nfc_code' AND COLUMN_NAME = 'tenant_assigned_admin_id'),
  'SELECT 1',
  'ALTER TABLE `la_merchant_nfc_code` ADD COLUMN `tenant_assigned_admin_id` int unsigned DEFAULT NULL COMMENT ''执行租户分配的平台管理员'' AFTER `created_admin_id`'
);
PREPARE nfc_statement FROM @nfc_alter;
EXECUTE nfc_statement;
DEALLOCATE PREPARE nfc_statement;

-- 同批分配共用一个 Unix 秒时间，历史未分配码牌保持 NULL。
SET @nfc_alter = IF(
  EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_merchant_nfc_code' AND COLUMN_NAME = 'tenant_assigned_at'),
  'SELECT 1',
  'ALTER TABLE `la_merchant_nfc_code` ADD COLUMN `tenant_assigned_at` int unsigned DEFAULT NULL COMMENT ''分配时间，Unix 秒'' AFTER `tenant_assigned_admin_id`'
);
PREPARE nfc_statement FROM @nfc_alter;
EXECUTE nfc_statement;
DEALLOCATE PREPARE nfc_statement;

-- 支持按租户归属筛选库存；同一租户允许拥有多块码牌，不设置唯一约束。
SET @nfc_alter = IF(
  EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_merchant_nfc_code' AND INDEX_NAME = 'idx_nfc_tenant'),
  'SELECT 1',
  'ALTER TABLE `la_merchant_nfc_code` ADD KEY `idx_nfc_tenant` (`tenant_id`, `id`)'
);
PREPARE nfc_statement FROM @nfc_alter;
EXECUTE nfc_statement;
DEALLOCATE PREPARE nfc_statement;

-- 分配接口和租户选项查询都登记为现有 NFC 页面下的操作权限。
-- 角色需同时授权这两项；无需修改公共中间件来映射或特判接口。
INSERT INTO `la_system_menu`
  (pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT p.id,'A',a.label,'',0,CONCAT('merchant.nfc_code/',a.action_name),'','','','',0,0,0,'','core',CONCAT('core_platform_merchant_nfc_code_',a.action_name),1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` p
CROSS JOIN (SELECT 'assignTenant' AS action_name, '分配给租户' AS label UNION ALL SELECT 'tenantChoices','租户选项查询') a
WHERE p.source_menu_key='core_platform_merchant_nfc_code'
  AND NOT EXISTS (SELECT 1 FROM `la_system_menu` e WHERE e.perms=CONCAT('merchant.nfc_code/',a.action_name));
