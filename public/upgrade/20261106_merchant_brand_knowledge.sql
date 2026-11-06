-- 品牌知识库：一级文件夹 + 富文本文章。
-- 预留 metadata_json / tags_json / ai_index_* 字段，后续可用于大模型索引与懒加载。

CREATE TABLE IF NOT EXISTS `la_merchant_brand_knowledge_folder` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `user_id` int unsigned NOT NULL COMMENT '商家用户ID',
  `brand_id` bigint unsigned NOT NULL COMMENT '所属品牌ID',
  `name` varchar(60) NOT NULL DEFAULT '' COMMENT '文件夹名称，只支持一级',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序，越大越靠前',
  `metadata_json` text NULL COMMENT '预留元数据JSON',
  `create_time` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int unsigned NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_brand_name` (`tenant_id`,`user_id`,`brand_id`,`name`,`delete_time`),
  KEY `idx_brand` (`tenant_id`,`user_id`,`brand_id`,`delete_time`,`sort`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='品牌知识库文件夹';

CREATE TABLE IF NOT EXISTS `la_merchant_brand_knowledge_article` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `user_id` int unsigned NOT NULL COMMENT '商家用户ID',
  `brand_id` bigint unsigned NOT NULL COMMENT '所属品牌ID',
  `folder_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '文件夹ID，0表示未分组',
  `title` varchar(120) NOT NULL DEFAULT '' COMMENT '文章标题',
  `content_html` mediumtext NULL COMMENT '富文本HTML',
  `content_text` mediumtext NULL COMMENT '纯文本内容',
  `summary` varchar(500) NOT NULL DEFAULT '' COMMENT '摘要',
  `tags_json` text NULL COMMENT '标签JSON数组',
  `metadata_json` text NULL COMMENT '预留元数据JSON',
  `content_hash` char(64) NOT NULL DEFAULT '' COMMENT '内容Hash',
  `token_estimate` int unsigned NOT NULL DEFAULT 0 COMMENT '预估Token数',
  `ai_index_status` varchar(20) NOT NULL DEFAULT 'none' COMMENT 'AI索引状态：none/pending/indexed/failed',
  `ai_index_version` int unsigned NOT NULL DEFAULT 0 COMMENT 'AI索引版本',
  `ai_indexed_at` int unsigned NOT NULL DEFAULT 0 COMMENT 'AI索引时间',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序，越大越靠前',
  `create_time` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int unsigned NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_brand_folder` (`tenant_id`,`user_id`,`brand_id`,`folder_id`,`delete_time`,`sort`,`id`),
  KEY `idx_brand_title` (`tenant_id`,`user_id`,`brand_id`,`delete_time`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='品牌知识库文章';
