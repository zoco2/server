-- 智能剪辑贴纸资源改按分类 code 关联，不再用 category_id。
-- 可重复执行。

-- 1) 补 category_code
SET @sql := IF(
  (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'la_smart_edit_sticker_resource'
      AND COLUMN_NAME = 'category_code'
  ) = 0,
  'ALTER TABLE `la_smart_edit_sticker_resource` ADD COLUMN `category_code` varchar(64) NOT NULL DEFAULT '''' COMMENT ''所属分类 code，同租户内与分类表 code 对应'' AFTER `tenant_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) 用本租户分类表回填 code（旧数据只有 category_id）
UPDATE `la_smart_edit_sticker_resource` r
INNER JOIN `la_smart_edit_sticker_category` c
  ON c.id = r.category_id
 AND c.tenant_id = r.tenant_id
SET r.category_code = c.code
WHERE r.category_code = ''
  AND r.category_id > 0;

-- 3) 换唯一键 / 索引
SET @sql := IF(
  (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'la_smart_edit_sticker_resource'
      AND INDEX_NAME = 'uk_tenant_category_file'
      AND COLUMN_NAME = 'category_id'
  ) > 0,
  'ALTER TABLE `la_smart_edit_sticker_resource` DROP INDEX `uk_tenant_category_file`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'la_smart_edit_sticker_resource'
      AND INDEX_NAME = 'idx_tenant_category_status_sort'
      AND COLUMN_NAME = 'category_id'
  ) > 0,
  'ALTER TABLE `la_smart_edit_sticker_resource` DROP INDEX `idx_tenant_category_status_sort`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'la_smart_edit_sticker_resource'
      AND INDEX_NAME = 'uk_tenant_category_file'
  ) = 0,
  'ALTER TABLE `la_smart_edit_sticker_resource` ADD UNIQUE KEY `uk_tenant_category_file` (`tenant_id`,`category_code`,`file_name`,`delete_time`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'la_smart_edit_sticker_resource'
      AND INDEX_NAME = 'idx_tenant_category_status_sort'
  ) = 0,
  'ALTER TABLE `la_smart_edit_sticker_resource` ADD KEY `idx_tenant_category_status_sort` (`tenant_id`,`category_code`,`status`,`sort`,`delete_time`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) 删除旧 category_id
SET @sql := IF(
  (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'la_smart_edit_sticker_resource'
      AND COLUMN_NAME = 'category_id'
  ) > 0,
  'ALTER TABLE `la_smart_edit_sticker_resource` DROP COLUMN `category_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
