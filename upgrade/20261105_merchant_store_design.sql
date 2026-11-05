-- 门店装修草稿。config / files 存 JSON。
-- 装修图片不走独立文件表，files 里记下上传接口返回的 id 和 URL。
-- 不做商家自定义模板，不建 la_merchant_store_design_template。

CREATE TABLE IF NOT EXISTS `la_merchant_store_design` (
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `user_id` int unsigned NOT NULL COMMENT '商家用户ID',
  `store_id` bigint unsigned NOT NULL COMMENT '门店ID',
  `config` mediumtext NOT NULL COMMENT '装修配置 JSON',
  `files` mediumtext NOT NULL COMMENT '装修引用图片 JSON，[{id,url}]',
  `revision` int unsigned NOT NULL DEFAULT 1 COMMENT '保存次数，每次保存+1',
  `update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`tenant_id`,`user_id`,`store_id`),
  KEY `idx_store` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店装修草稿';

DROP TABLE IF EXISTS `la_merchant_store_design_template`;
