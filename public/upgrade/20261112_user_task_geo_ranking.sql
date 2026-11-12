SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_user_task_plan' AND COLUMN_NAME = 'job_config') = 0, 'ALTER TABLE `la_user_task_plan` ADD COLUMN `job_config` text NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_user_task_run' AND COLUMN_NAME = 'job_config') = 0, 'ALTER TABLE `la_user_task_run` ADD COLUMN `job_config` text NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_user_task_run' AND COLUMN_NAME = 'workflow_step') = 0, 'ALTER TABLE `la_user_task_run` ADD COLUMN `workflow_step` varchar(24) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_user_task_run' AND COLUMN_NAME = 'workflow_data') = 0, 'ALTER TABLE `la_user_task_run` ADD COLUMN `workflow_data` text NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_user_task_run' AND COLUMN_NAME = 'next_step_at') = 0, 'ALTER TABLE `la_user_task_run` ADD COLUMN `next_step_at` bigint unsigned DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_user_task_run' AND INDEX_NAME = 'idx_geo_step_due') = 0, 'ALTER TABLE `la_user_task_run` ADD KEY `idx_geo_step_due` (`status`,`job_type`,`next_step_at`,`id`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_batch' AND COLUMN_NAME = 'task_run_id') = 0, 'ALTER TABLE `la_geo_diag_batch` ADD COLUMN `task_run_id` bigint unsigned DEFAULT NULL COMMENT ''计划执行记录 ID，手动检测为空''', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_batch' AND INDEX_NAME = 'uk_task_run') = 0, 'ALTER TABLE `la_geo_diag_batch` ADD UNIQUE KEY `uk_task_run` (`task_run_id`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `la_user_task_run_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint unsigned NOT NULL,
  `step` varchar(24) NOT NULL,
  `status` varchar(16) NOT NULL,
  `message` varchar(255) NOT NULL,
  `details` text NOT NULL,
  `create_time` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_run_event` (`run_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户计划任务执行进度';
