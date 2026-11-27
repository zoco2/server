-- 记录首次绑定的登录用户。历史绑定保持 NULL，避免把当前门店归属当成操作人。
SET @nfc_alter = IF(
  EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_merchant_nfc_code' AND COLUMN_NAME = 'bound_user_id'),
  'SELECT 1',
  'ALTER TABLE `la_merchant_nfc_code` ADD COLUMN `bound_user_id` int unsigned DEFAULT NULL COMMENT ''首次绑定的用户 ID'' AFTER `store_id`'
);
PREPARE nfc_statement FROM @nfc_alter;
EXECUTE nfc_statement;
DEALLOCATE PREPARE nfc_statement;
