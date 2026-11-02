-- 品牌资料的四段正文。创建品牌时留空，之后按段单独保存。
-- text 不能带默认值，已有行补 NULL，接口读出时按空字符串返回。

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_merchant_brand' AND COLUMN_NAME='company_intro')=0,
 'ALTER TABLE `la_merchant_brand` ADD COLUMN `company_intro` text NULL COMMENT ''企业介绍'' AFTER `cover_url`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_merchant_brand' AND COLUMN_NAME='products_services')=0,
 'ALTER TABLE `la_merchant_brand` ADD COLUMN `products_services` text NULL COMMENT ''产品与服务'' AFTER `company_intro`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_merchant_brand' AND COLUMN_NAME='target_customers')=0,
 'ALTER TABLE `la_merchant_brand` ADD COLUMN `target_customers` text NULL COMMENT ''目标客户与地区'' AFTER `products_services`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_merchant_brand' AND COLUMN_NAME='competitor_facts')=0,
 'ALTER TABLE `la_merchant_brand` ADD COLUMN `competitor_facts` text NULL COMMENT ''竞品与差异事实'' AFTER `target_customers`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
