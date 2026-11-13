-- 兼容已执行早期建表脚本的站点。只补缺失字段，保留已有任务数据。
-- 无法确认归属的历史任务保留 NULL。所有任务读写都要求有效的租户 ID 和用户 ID。
SET @publish_task_add_tenant_id := IF(
  EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_publish_task')
  AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_publish_task' AND COLUMN_NAME = 'tenant_id'),
  'ALTER TABLE `la_publish_task` ADD COLUMN `tenant_id` int unsigned DEFAULT NULL COMMENT ''所属租户 ID'' AFTER `id`',
  'SELECT 1'
);
PREPARE publish_task_schema FROM @publish_task_add_tenant_id;
EXECUTE publish_task_schema;
DEALLOCATE PREPARE publish_task_schema;

SET @publish_task_add_owner_index := IF(
  EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_publish_task' AND COLUMN_NAME = 'tenant_id')
  AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_publish_task' AND INDEX_NAME = 'idx_owner'),
  'ALTER TABLE `la_publish_task` ADD INDEX `idx_owner` (`tenant_id`, `user_id`, `id`)',
  'SELECT 1'
);
PREPARE publish_task_schema FROM @publish_task_add_owner_index;
EXECUTE publish_task_schema;
DEALLOCATE PREPARE publish_task_schema;

SET @publish_task_add_staff_id := IF(
  EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_publish_task')
  AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_publish_task' AND COLUMN_NAME = 'staff_id'),
  'ALTER TABLE `la_publish_task` ADD COLUMN `staff_id` bigint unsigned DEFAULT NULL COMMENT ''接收通知的员工 ID，空表示通知创建人'' AFTER `user_id`',
  'SELECT 1'
);
PREPARE publish_task_schema FROM @publish_task_add_staff_id;
EXECUTE publish_task_schema;
DEALLOCATE PREPARE publish_task_schema;

SET @publish_task_add_next_wake_at := IF(
  EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_publish_task')
  AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_publish_task' AND COLUMN_NAME = 'next_wake_at'),
  'ALTER TABLE `la_publish_task` ADD COLUMN `next_wake_at` bigint unsigned DEFAULT NULL COMMENT ''下一条 MNS 消息的计划唤醒时间（Unix 秒）'' AFTER `status`',
  'SELECT 1'
);
PREPARE publish_task_schema FROM @publish_task_add_next_wake_at;
EXECUTE publish_task_schema;
DEALLOCATE PREPARE publish_task_schema;
