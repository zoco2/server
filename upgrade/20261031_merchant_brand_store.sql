-- 品牌、门店、员工。归属是 tenant_id + user_id（商家账号）。
-- 品牌对门店、门店对员工都是一对多：门店.brand_id、员工.store_id。
-- 图片直接存 URL，空字符串表示未上传。delete_time 为空表示未删除，与 SoftDelete 一致。

CREATE TABLE IF NOT EXISTS `la_merchant_brand` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `user_id` int unsigned NOT NULL COMMENT '商家用户ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '品牌名称',
  `industry` varchar(120) NOT NULL DEFAULT '' COMMENT '所属行业',
  `contact_name` varchar(60) NOT NULL DEFAULT '' COMMENT '联系人',
  `contact_phone` varchar(32) NOT NULL DEFAULT '' COMMENT '联系电话，手机号、座机或客服电话',
  `contact_email` varchar(120) NOT NULL DEFAULT '' COMMENT '联系邮箱',
  `website` varchar(255) NOT NULL DEFAULT '' COMMENT '官方网站',
  `address` varchar(255) NOT NULL DEFAULT '' COMMENT '联系地址',
  `logo_url` varchar(500) NOT NULL DEFAULT '' COMMENT '品牌Logo URL',
  `cover_url` varchar(500) NOT NULL DEFAULT '' COMMENT '品牌封面 URL',
  `create_time` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int unsigned NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_name` (`tenant_id`,`user_id`,`name`,`delete_time`),
  KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商家品牌';

CREATE TABLE IF NOT EXISTS `la_merchant_store` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `user_id` int unsigned NOT NULL COMMENT '商家用户ID',
  `brand_id` bigint unsigned NOT NULL COMMENT '所属品牌ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '门店名称',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '营业状态：1营业中，0已停用',
  `phone` varchar(32) NOT NULL DEFAULT '' COMMENT '门店电话',
  `contact_name` varchar(60) NOT NULL DEFAULT '' COMMENT '联系人',
  `business_hours` varchar(64) NOT NULL DEFAULT '' COMMENT '营业时间，例如09:00-22:00',
  `province` varchar(40) NOT NULL DEFAULT '' COMMENT '省/直辖市',
  `city` varchar(40) NOT NULL DEFAULT '' COMMENT '城市',
  `district` varchar(40) NOT NULL DEFAULT '' COMMENT '区/县',
  `address` varchar(255) NOT NULL DEFAULT '' COMMENT '详细地址',
  `latitude` decimal(10,7) DEFAULT NULL COMMENT '纬度',
  `longitude` decimal(10,7) DEFAULT NULL COMMENT '经度',
  `poi_id` varchar(64) NOT NULL DEFAULT '' COMMENT 'POI ID',
  `intro` varchar(1000) NOT NULL DEFAULT '' COMMENT '门店简介',
  `logo_url` varchar(500) NOT NULL DEFAULT '' COMMENT '门店Logo URL',
  `create_time` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int unsigned NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`,`id`),
  KEY `idx_brand` (`tenant_id`,`user_id`,`brand_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商家门店';

CREATE TABLE IF NOT EXISTS `la_merchant_staff` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL COMMENT '租户ID',
  `user_id` int unsigned NOT NULL COMMENT '商家用户ID，不是员工登录账号',
  `store_id` bigint unsigned NOT NULL COMMENT '所属门店ID',
  `name` varchar(60) NOT NULL DEFAULT '' COMMENT '员工姓名',
  `mobile` char(11) NOT NULL DEFAULT '' COMMENT '11位手机号',
  `appid` varchar(64) DEFAULT NULL COMMENT '公众号AppID，未关联为空',
  `openid` varchar(128) DEFAULT NULL COMMENT '公众号OpenID，未关联为空',
  `create_time` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int unsigned NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_mobile` (`tenant_id`,`user_id`,`store_id`,`mobile`,`delete_time`),
  UNIQUE KEY `uk_oa_openid` (`tenant_id`,`appid`,`openid`,`delete_time`),
  KEY `idx_store` (`tenant_id`,`user_id`,`store_id`,`delete_time`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店员工';
