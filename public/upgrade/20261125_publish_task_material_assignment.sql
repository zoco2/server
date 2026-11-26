ALTER TABLE `la_publish_task`
  ADD COLUMN `work_group_ids` json DEFAULT NULL COMMENT '成片组配置，按素材类型保存，0 表示默认组';

CREATE TABLE IF NOT EXISTS `la_publish_task_material_assignment` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL COMMENT '任务及素材所有人',
  `run_id` bigint unsigned NOT NULL COMMENT '发布任务执行轮次',
  `staff_id` int unsigned NOT NULL,
  `platform` varchar(24) NOT NULL,
  `video_ref` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `finished_image_ref` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `copy_ref` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `topic_ref` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `materials` json NOT NULL COMMENT '分配时的素材标题、引用及文案话题内容',
  `create_time` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_run_staff_platform` (`tenant_id`, `run_id`, `staff_id`, `platform`),
  KEY `idx_video_usage` (`tenant_id`, `user_id`, `video_ref`),
  KEY `idx_image_usage` (`tenant_id`, `user_id`, `finished_image_ref`),
  KEY `idx_copy_usage` (`tenant_id`, `user_id`, `copy_ref`),
  KEY `idx_topic_usage` (`tenant_id`, `user_id`, `topic_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='员工按轮次和平台锁定的发布素材';
