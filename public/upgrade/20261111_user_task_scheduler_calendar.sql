-- 兼容已安装早期计划任务表的站点。全新安装时各字段已存在。
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_user_task_plan' AND COLUMN_NAME = 'schedule_type') = 0,
    'ALTER TABLE `la_user_task_plan`
        ADD COLUMN `schedule_type` varchar(16) DEFAULT NULL AFTER `interval_seconds`,
        ADD COLUMN `schedule_config` text NULL AFTER `schedule_type`,
        ADD COLUMN `timezone` varchar(64) NOT NULL DEFAULT ''Asia/Shanghai'' AFTER `schedule_config`,
        ADD COLUMN `end_at` bigint unsigned DEFAULT NULL COMMENT ''第一个不可执行的 Unix 秒'' AFTER `timezone`,
        ADD KEY `idx_end` (`status`,`end_at`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_user_task_run' AND COLUMN_NAME = 'expires_at') = 0,
    'ALTER TABLE `la_user_task_run`
        ADD COLUMN `expires_at` bigint unsigned DEFAULT NULL COMMENT ''排队记录领取截止时间'' AFTER `scheduled_at`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
