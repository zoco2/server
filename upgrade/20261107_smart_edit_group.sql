-- 智能剪辑：镜头组。一个组下有多个镜头，每个镜头下挂多条候选视频；
-- 组内各镜头各取一条做笛卡尔积，每个组合生成一条普通剪辑任务。
-- 与现有单任务链路的唯一关系：任务表多两个列，batch_no 为空串即单任务。

CREATE TABLE IF NOT EXISTS `la_smart_edit_group` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `batch_no` varchar(40) NOT NULL DEFAULT '' COMMENT '对外组号',
  `title` varchar(200) NOT NULL DEFAULT '',
  `shots_json` text NULL COMMENT '镜头定义快照',
  `config_json` text NULL COMMENT '整组共用配置快照',
  `combo_count` int unsigned NOT NULL DEFAULT 0 COMMENT '组合总数',
  `status` varchar(20) NOT NULL DEFAULT 'expanding' COMMENT 'expanding/submitted/failed',
  `error_code` varchar(80) NOT NULL DEFAULT '',
  `error_message` varchar(500) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch_no` (`batch_no`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='智能剪辑镜头组';

-- 任务表补两列：batch_no 关联组，combo_index 标记组内第几个组合。
-- 用 information_schema 判断后再 ALTER，脚本可重复执行。
SET @smart_edit_group_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = REPLACE('`la_smart_edit_task`', '`', '')
      AND COLUMN_NAME = 'batch_no') = 0,
  'ALTER TABLE `la_smart_edit_task` ADD COLUMN `batch_no` varchar(40) NOT NULL DEFAULT '''' COMMENT ''所属组号，空串=单任务'' AFTER `title`',
  'SELECT 1'
);
PREPARE smart_edit_group_stmt FROM @smart_edit_group_sql;
EXECUTE smart_edit_group_stmt;
DEALLOCATE PREPARE smart_edit_group_stmt;

SET @smart_edit_group_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = REPLACE('`la_smart_edit_task`', '`', '')
      AND COLUMN_NAME = 'combo_index') = 0,
  'ALTER TABLE `la_smart_edit_task` ADD COLUMN `combo_index` int unsigned NOT NULL DEFAULT 0 COMMENT ''组内组合序号，从 1 起'' AFTER `batch_no`',
  'SELECT 1'
);
PREPARE smart_edit_group_stmt FROM @smart_edit_group_sql;
EXECUTE smart_edit_group_stmt;
DEALLOCATE PREPARE smart_edit_group_stmt;

SET @smart_edit_group_sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = REPLACE('`la_smart_edit_task`', '`', '')
      AND INDEX_NAME = 'idx_batch') = 0,
  'ALTER TABLE `la_smart_edit_task` ADD KEY `idx_batch` (`batch_no`,`combo_index`)',
  'SELECT 1'
);
PREPARE smart_edit_group_stmt FROM @smart_edit_group_sql;
EXECUTE smart_edit_group_stmt;
DEALLOCATE PREPARE smart_edit_group_stmt;
