CREATE TABLE IF NOT EXISTS `la_merchant_topic` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `user_id` int unsigned NOT NULL COMMENT '商家用户ID',
  `name` varchar(100) NOT NULL COMMENT '话题名称',
  `create_time` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`tenant_id`, `user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商家话题库';
