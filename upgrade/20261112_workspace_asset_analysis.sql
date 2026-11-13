-- Store AI analysis on the uploaded material itself (la_tenant_file).
-- Only user local uploads carry analysis; generated results are untouched.
-- Idempotent: each column is added only when the table exists and the column is missing.

SET @tbl := 'la_tenant_file';

SET @col := 'analysis_json';
SET @sql := (SELECT IF(COUNT(*) > 0 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col) = 0,
  CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` longtext NULL'), 'SELECT 1')
  FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := 'analysis_model';
SET @sql := (SELECT IF(COUNT(*) > 0 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col) = 0,
  CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` varchar(64) NULL'), 'SELECT 1')
  FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := 'analysis_time';
SET @sql := (SELECT IF(COUNT(*) > 0 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl AND COLUMN_NAME = @col) = 0,
  CONCAT('ALTER TABLE `', @tbl, '` ADD COLUMN `', @col, '` int unsigned NOT NULL DEFAULT 0'), 'SELECT 1')
  FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @tbl);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
