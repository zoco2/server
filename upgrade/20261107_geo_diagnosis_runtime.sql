-- GEO 品牌诊断运行时字段。兼容已经执行过 20261104_geo_diagnosis.sql 的库。

CREATE TABLE IF NOT EXISTS `la_geo_diag_batch` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `merchant_brand_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '系统品牌ID',
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '诊断名称',
  `brand_name` varchar(120) NOT NULL DEFAULT '',
  `brand_aliases_json` text NULL COMMENT '品牌别名，本地命中用',
  `question_count` int unsigned NOT NULL DEFAULT 0,
  `platforms_json` text NULL COMMENT '平台ID数组',
  `region_code` varchar(12) NOT NULL DEFAULT '' COMMENT '省份代码，仅Web',
  `repeat_count` tinyint unsigned NOT NULL DEFAULT 1,
  `screenshot` tinyint unsigned NOT NULL DEFAULT 0,
  `conversation_link` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否要求平台对话链接',
  `unit_count` int unsigned NOT NULL DEFAULT 0 COMMENT '采集单元数',
  `status` varchar(20) NOT NULL DEFAULT 'queued' COMMENT 'queued/running/completed/partial_failed/failed/cancelled',
  `terminal` tinyint unsigned NOT NULL DEFAULT 0,
  `completed_count` int unsigned NOT NULL DEFAULT 0,
  `failed_count` int unsigned NOT NULL DEFAULT 0,
  `poll_count` smallint unsigned NOT NULL DEFAULT 0,
  `next_poll_time` int unsigned NOT NULL DEFAULT 0,
  `mention_rate` decimal(6,4) NOT NULL DEFAULT 0,
  `coverage_rate` decimal(6,4) NOT NULL DEFAULT 0,
  `citation_rate` decimal(6,4) NOT NULL DEFAULT 0,
  `summary_json` text NULL COMMENT '服务商report summary与本地汇总',
  `upstream_batch_id` varchar(64) NOT NULL DEFAULT '' COMMENT '服务商批次ID',
  `idempotency_key` varchar(80) NOT NULL DEFAULT '' COMMENT '服务商批次幂等键',
  `provider_request_id` varchar(80) NOT NULL DEFAULT '',
  `charged_cents` int unsigned NOT NULL DEFAULT 0,
  `balance_cents` int unsigned NOT NULL DEFAULT 0,
  `request_json` mediumtext NULL,
  `response_json` mediumtext NULL,
  `status_json` mediumtext NULL,
  `error` varchar(500) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_idempotency` (`idempotency_key`),
  KEY `idx_tenant` (`tenant_id`,`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`id`),
  KEY `idx_brand` (`tenant_id`,`user_id`,`merchant_brand_id`,`id`),
  KEY `idx_status` (`status`),
  KEY `idx_poll` (`terminal`,`next_poll_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GEO诊断批次';

CREATE TABLE IF NOT EXISTS `la_geo_diag_run` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `batch_id` int unsigned NOT NULL DEFAULT 0,
  `batch_item_index` int unsigned NOT NULL DEFAULT 0,
  `prompt` varchar(2000) NOT NULL DEFAULT '',
  `platforms_json` text NULL COMMENT '本次提交的平台数组',
  `idempotency_key` varchar(80) NOT NULL DEFAULT '' COMMENT '服务商幂等键，重试必须复用',
  `upstream_run_id` varchar(64) NOT NULL DEFAULT '' COMMENT '服务商runId',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/queued/running/completed/partial_failed/failed/cancelled',
  `terminal` tinyint unsigned NOT NULL DEFAULT 0,
  `poll_count` smallint unsigned NOT NULL DEFAULT 0,
  `next_poll_time` int unsigned NOT NULL DEFAULT 0,
  `provider_request_id` varchar(80) NOT NULL DEFAULT '',
  `charged_cents` int unsigned NOT NULL DEFAULT 0,
  `balance_cents` int unsigned NOT NULL DEFAULT 0,
  `request_json` mediumtext NULL,
  `response_json` mediumtext NULL,
  `status_json` mediumtext NULL,
  `result_json` mediumtext NULL,
  `report_json` mediumtext NULL,
  `error` varchar(500) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_idempotency` (`idempotency_key`),
  KEY `idx_batch` (`batch_id`),
  KEY `idx_batch_item` (`batch_id`,`batch_item_index`),
  KEY `idx_poll` (`terminal`,`next_poll_time`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GEO诊断任务';

CREATE TABLE IF NOT EXISTS `la_geo_diag_sample` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `batch_id` int unsigned NOT NULL DEFAULT 0,
  `run_id` int unsigned NOT NULL DEFAULT 0,
  `upstream_sample_id` varchar(80) NOT NULL DEFAULT '',
  `prompt` varchar(2000) NOT NULL DEFAULT '',
  `prompt_id` varchar(80) NOT NULL DEFAULT '',
  `platform_id` varchar(40) NOT NULL DEFAULT '',
  `platform_name` varchar(80) NOT NULL DEFAULT '',
  `client` varchar(10) NOT NULL DEFAULT '' COMMENT 'web/mobile',
  `sample_index` tinyint unsigned NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT '',
  `error_code` varchar(60) NOT NULL DEFAULT '',
  `error_message` varchar(500) NOT NULL DEFAULT '',
  `answer_content` mediumtext NULL,
  `brand_mentioned` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '服务商判断',
  `local_mentioned` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '本地品牌别名命中',
  `mention_count` int unsigned NOT NULL DEFAULT 0,
  `mention_position` int unsigned NOT NULL DEFAULT 0,
  `sentiment` varchar(20) NOT NULL DEFAULT '',
  `citations_json` text NULL COMMENT '实际引用[{url,title}]',
  `references_json` text NULL COMMENT '额外来源集合',
  `competitors_json` text NULL COMMENT '竞品排行',
  `goods_json` text NULL COMMENT '商品卡',
  `provider_result_json` mediumtext NULL,
  `monitoring_options_json` text NULL,
  `requested_region_code` text NULL,
  `screenshot_url` varchar(1000) NOT NULL DEFAULT '',
  `response_url` varchar(1000) NOT NULL DEFAULT '',
  `quoted_price_cents` int unsigned NOT NULL DEFAULT 0,
  `raw_json` mediumtext NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_run_sample` (`run_id`,`platform_id`,`sample_index`),
  KEY `idx_batch` (`batch_id`),
  KEY `idx_tenant_platform` (`tenant_id`,`platform_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GEO诊断平台采样结果';

SET @t = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_batch');
SET @sql = IF(@t > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_batch' AND COLUMN_NAME = 'merchant_brand_id') = 0, 'ALTER TABLE `la_geo_diag_batch` ADD COLUMN `merchant_brand_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT ''系统品牌ID'' AFTER `user_id`, ADD KEY `idx_brand` (`tenant_id`,`user_id`,`merchant_brand_id`,`id`)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@t > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_batch' AND COLUMN_NAME = 'conversation_link') = 0, 'ALTER TABLE `la_geo_diag_batch` ADD COLUMN `conversation_link` tinyint unsigned NOT NULL DEFAULT 0 COMMENT ''是否要求平台对话链接'' AFTER `screenshot`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@t > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_batch' AND COLUMN_NAME = 'terminal') = 0, 'ALTER TABLE `la_geo_diag_batch` ADD COLUMN `terminal` tinyint unsigned NOT NULL DEFAULT 0 AFTER `status`, ADD COLUMN `completed_count` int unsigned NOT NULL DEFAULT 0 AFTER `terminal`, ADD COLUMN `failed_count` int unsigned NOT NULL DEFAULT 0 AFTER `completed_count`, ADD COLUMN `poll_count` smallint unsigned NOT NULL DEFAULT 0 AFTER `failed_count`, ADD COLUMN `next_poll_time` int unsigned NOT NULL DEFAULT 0 AFTER `poll_count`, ADD KEY `idx_poll` (`terminal`,`next_poll_time`)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@t > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_batch' AND COLUMN_NAME = 'upstream_batch_id') = 0, 'ALTER TABLE `la_geo_diag_batch` ADD COLUMN `upstream_batch_id` varchar(64) NOT NULL DEFAULT '''' AFTER `summary_json`, ADD COLUMN `idempotency_key` varchar(80) NOT NULL DEFAULT '''' AFTER `upstream_batch_id`, ADD COLUMN `provider_request_id` varchar(80) NOT NULL DEFAULT '''' AFTER `idempotency_key`, ADD COLUMN `charged_cents` int unsigned NOT NULL DEFAULT 0 AFTER `provider_request_id`, ADD COLUMN `balance_cents` int unsigned NOT NULL DEFAULT 0 AFTER `charged_cents`, ADD COLUMN `request_json` mediumtext NULL AFTER `balance_cents`, ADD COLUMN `response_json` mediumtext NULL AFTER `request_json`, ADD COLUMN `status_json` mediumtext NULL AFTER `response_json`, ADD COLUMN `error` varchar(500) NOT NULL DEFAULT '''' AFTER `status_json`, ADD KEY `idx_idempotency` (`idempotency_key`)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_run');
SET @sql = IF(@t > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_run' AND COLUMN_NAME = 'batch_item_index') = 0, 'ALTER TABLE `la_geo_diag_run` ADD COLUMN `batch_item_index` int unsigned NOT NULL DEFAULT 0 AFTER `batch_id`, ADD KEY `idx_batch_item` (`batch_id`,`batch_item_index`)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@t > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_run' AND COLUMN_NAME = 'provider_request_id') = 0, 'ALTER TABLE `la_geo_diag_run` ADD COLUMN `provider_request_id` varchar(80) NOT NULL DEFAULT '''' AFTER `next_poll_time`, ADD COLUMN `charged_cents` int unsigned NOT NULL DEFAULT 0 AFTER `provider_request_id`, ADD COLUMN `balance_cents` int unsigned NOT NULL DEFAULT 0 AFTER `charged_cents`, ADD COLUMN `request_json` mediumtext NULL AFTER `balance_cents`, ADD COLUMN `response_json` mediumtext NULL AFTER `request_json`, ADD COLUMN `status_json` mediumtext NULL AFTER `response_json`, ADD COLUMN `result_json` mediumtext NULL AFTER `status_json`, ADD COLUMN `report_json` mediumtext NULL AFTER `result_json`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_sample');
SET @sql = IF(@t > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_sample' AND COLUMN_NAME = 'upstream_sample_id') = 0, 'ALTER TABLE `la_geo_diag_sample` ADD COLUMN `upstream_sample_id` varchar(80) NOT NULL DEFAULT '''' AFTER `run_id`, ADD COLUMN `prompt` varchar(2000) NOT NULL DEFAULT '''' AFTER `upstream_sample_id`, ADD COLUMN `prompt_id` varchar(80) NOT NULL DEFAULT '''' AFTER `prompt`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@t > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_geo_diag_sample' AND COLUMN_NAME = 'goods_json') = 0, 'ALTER TABLE `la_geo_diag_sample` ADD COLUMN `goods_json` text NULL AFTER `competitors_json`, ADD COLUMN `provider_result_json` mediumtext NULL AFTER `goods_json`, ADD COLUMN `monitoring_options_json` text NULL AFTER `provider_result_json`, ADD COLUMN `requested_region_code` text NULL AFTER `monitoring_options_json`, ADD COLUMN `raw_json` mediumtext NULL AFTER `quoted_price_cents`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
