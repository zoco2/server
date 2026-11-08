-- 贴纸表归属智能剪辑，表名与 la_smart_edit_* 前缀统一。
-- 已有库：旧表存在且新表不存在时 RENAME；可重复执行。

SET @sticker_rename_sql := IF(
  (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'la_sticker_category'
  ) > 0
  AND (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'la_smart_edit_sticker_category'
  ) = 0,
  'RENAME TABLE `la_sticker_category` TO `la_smart_edit_sticker_category`',
  'SELECT 1'
);
PREPARE sticker_rename_stmt FROM @sticker_rename_sql;
EXECUTE sticker_rename_stmt;
DEALLOCATE PREPARE sticker_rename_stmt;

SET @sticker_rename_sql := IF(
  (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'la_sticker_resource'
  ) > 0
  AND (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'la_smart_edit_sticker_resource'
  ) = 0,
  'RENAME TABLE `la_sticker_resource` TO `la_smart_edit_sticker_resource`',
  'SELECT 1'
);
PREPARE sticker_rename_stmt FROM @sticker_rename_sql;
EXECUTE sticker_rename_stmt;
DEALLOCATE PREPARE sticker_rename_stmt;
