CREATE TABLE IF NOT EXISTS `la_work_group` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL COMMENT '作品库所属用户',
  `type` ENUM('video', 'finished_image', 'copy', 'topic') NOT NULL COMMENT '视频、成品图片、文案、发布话题',
  `name` VARCHAR(100) NOT NULL COMMENT '分组名称',
  `max_uses` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '组内每条作品的使用上限，0 表示不限',
  `create_time` INT UNSIGNED NOT NULL,
  `update_time` INT UNSIGNED NOT NULL,
  `delete_time` INT UNSIGNED DEFAULT NULL COMMENT 'NULL 表示正常',
  PRIMARY KEY (`id`),
  KEY `idx_owner_type` (`tenant_id`, `user_id`, `type`, `delete_time`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作品库分组';

CREATE TABLE IF NOT EXISTS `la_work_item` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL COMMENT '作品库所属用户',
  `type` ENUM('video', 'finished_image', 'copy', 'topic') NOT NULL,
  `group_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'la_work_group.id，0 表示未分组',
  `source_ref` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '带来源的稳定作品标识，例如 video:result:83 或 merchant_copy:37',
  `title` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '作品库展示标题',
  `create_time` INT UNSIGNED NOT NULL,
  `update_time` INT UNSIGNED NOT NULL,
  `delete_time` INT UNSIGNED DEFAULT NULL COMMENT 'NULL 表示正常',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_source` (`tenant_id`, `user_id`, `source_ref`),
  KEY `idx_owner_type` (`tenant_id`, `user_id`, `type`, `delete_time`, `id`),
  KEY `idx_owner_group` (`tenant_id`, `user_id`, `group_id`, `type`, `delete_time`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='作品库，一条成品一行';
