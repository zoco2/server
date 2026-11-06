-- MNS 队列最终失败任务记录表。
-- 只在任务达到最大接收次数后写入；普通业务数据不经过此表。

CREATE TABLE IF NOT EXISTS `la_tenant_failed_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `queue` varchar(100) NOT NULL DEFAULT '',
  `job_class` varchar(255) NOT NULL DEFAULT '',
  `job_id` varchar(100) NOT NULL DEFAULT '',
  `payload` mediumtext NULL COMMENT '原始消息体 JSON',
  `exception` varchar(2000) NOT NULL DEFAULT '',
  `failed_at` int unsigned NOT NULL DEFAULT 0,
  `retry_count` int unsigned NOT NULL DEFAULT 0 COMMENT '达到上限时的接收次数',
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0待处理 1已重试成功 2已放弃',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_queue_failed` (`queue`, `failed_at`),
  KEY `idx_status` (`status`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='队列失败任务';
