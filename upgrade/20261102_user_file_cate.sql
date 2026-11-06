-- 用户文件分组。与后台 la_tenant_file_cate 分开，已有用户文件 cid 仍为 0。
CREATE TABLE IF NOT EXISTS `la_user_file_cate` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `tenant_id` int(11) NOT NULL COMMENT '租户ID',
  `user_id` int(10) UNSIGNED NOT NULL COMMENT '用户ID',
  `pid` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父级ID',
  `type` tinyint(2) UNSIGNED NOT NULL DEFAULT 10 COMMENT '类型[10=图片，20=视频，30=文件]',
  `name` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '分类名称',
  `create_time` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间',
  `delete_time` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_tenant_user_type_id` (`tenant_id`, `user_id`, `type`, `id`) USING BTREE,
  KEY `idx_pid` (`pid`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '用户文件分组' ROW_FORMAT = Dynamic;
