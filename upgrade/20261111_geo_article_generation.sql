CREATE TABLE IF NOT EXISTS `la_geo_article_generation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` char(32) NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `brand_id` bigint unsigned NOT NULL,
  `prompt` text NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'queued',
  `message` mediumtext NULL,
  `error` varchar(255) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL,
  `start_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GEO 稿件生成任务';
