-- 智能剪辑标题字体库。
-- 平台预置数据 tenant_id = 0；文件已上传到 OSS fonts/ 目录，uri 不含域名。
-- 脚本可重复执行：建表 IF NOT EXISTS，写入 INSERT IGNORE（命中唯一键则跳过）。

CREATE TABLE IF NOT EXISTS `la_smart_edit_font` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0=平台预置',
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '字体标识，同租户内唯一，提交任务用',
  `name` varchar(128) NOT NULL DEFAULT '' COMMENT '展示名称',
  `file_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'OSS 文件名',
  `uri` varchar(500) NOT NULL DEFAULT '' COMMENT '对象路径，不含域名，如 fonts/新青年.ttf',
  `mime_type` varchar(100) NOT NULL DEFAULT '' COMMENT 'MIME 类型',
  `file_size` bigint unsigned NOT NULL DEFAULT 0 COMMENT '字节，0=未知',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform' COMMENT '存储作用域',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'aliyun' COMMENT '存储引擎',
  `storage_domain` varchar(255) NOT NULL DEFAULT '' COMMENT '存储域名',
  `preview` varchar(255) NOT NULL DEFAULT '' COMMENT '前端预览说明',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序，越大越靠前',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态:0停用 1启用',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`,`delete_time`),
  UNIQUE KEY `uk_tenant_file` (`tenant_id`,`file_name`,`delete_time`),
  KEY `idx_tenant_status_sort` (`tenant_id`,`status`,`sort`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='智能剪辑标题字体';

INSERT IGNORE INTO `la_smart_edit_font`
  (`tenant_id`, `code`, `name`, `file_name`, `uri`, `mime_type`, `file_size`, `storage_scope`, `storage_engine`, `storage_domain`, `sort`, `status`, `create_time`, `update_time`) VALUES
  (0, 'xinqingnian', '新青年', '新青年.ttf', 'fonts/新青年.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 29, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'aa-jianhao', 'Aa剑豪体', 'Aa剑豪体.ttf', 'fonts/Aa剑豪体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 28, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'baotu-xiaobai', '包图小白体', '包图小白体.ttf', 'fonts/包图小白体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 27, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'jingnan-bobohei', '荆南波波黑', '荆南波波黑.otf', 'fonts/荆南波波黑.otf', 'font/otf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 26, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'canger-shuyuan', '苍耳舒圆体', '苍耳舒圆体.ttf', 'fonts/苍耳舒圆体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 25, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'chuangketie-jingang', '创客贴金刚体', '创客贴金刚体.otf', 'fonts/创客贴金刚体.otf', 'font/otf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 24, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'yuanjing-heiti', '源影黑体', '源影黑体.ttf', 'fonts/源影黑体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 23, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'honglei-xingshu', '鸿雷行书简体', '鸿雷行书简体.otf', 'fonts/鸿雷行书简体.otf', 'font/otf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 22, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'maoken-shijin', '猫啃什锦体', '猫啃什锦体.ttf', 'fonts/猫啃什锦体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 21, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'muyao-softbrush', '沐瑶软笔手写体', '沐瑶软笔手写体.ttf', 'fonts/沐瑶软笔手写体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 20, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'qiantu-xiaotu', '千小图体', '千小图体.ttf', 'fonts/千小图体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 19, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'sanji-pomo', '三极泼墨体', '三极泼墨体.ttf', 'fonts/三极泼墨体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 18, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'yanshi-foxi', '演示佛系体', '演示佛系体.ttf', 'fonts/演示佛系体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 17, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'biaoxiaozhi-wujiehei', '标小智无界黑', '标小智无界黑.ttf', 'fonts/标小智无界黑.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 16, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'youshe-biaotiyuan', '优设标题圆', '优设标题圆.otf', 'fonts/优设标题圆.otf', 'font/otf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 15, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'aa-houdihei', 'Aa厚底黑', 'Aa厚底黑.ttf', 'fonts/Aa厚底黑.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 14, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'alibaba-puhui', '阿里巴巴普惠体', '阿里巴巴普惠体.ttf', 'fonts/阿里巴巴普惠体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 13, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'alimama-fangyuan', '阿里妈妈方圆体', '阿里妈妈方圆体.ttf', 'fonts/阿里妈妈方圆体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 12, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'shiwei-yongtui', '狮尾咏腿黑体', '狮尾咏腿黑体.ttf', 'fonts/狮尾咏腿黑体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 11, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'zizhiqu-ximai', '自制区喜脉体', '自制区喜脉体.ttf', 'fonts/自制区喜脉体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 10, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'zitiquan-weijunhei-w3', '字体圈伟君黑W3', '字体圈伟君黑W3.ttf', 'fonts/字体圈伟君黑W3.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 9, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'alimama-dongfangdakai', '阿里妈妈东方大楷', '阿里妈妈东方大楷.ttf', 'fonts/阿里妈妈东方大楷.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 8, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'zitiquan-xinyiguanhei', '字体圈欣意冠黑体', '字体圈欣意冠黑体.ttf', 'fonts/字体圈欣意冠黑体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 7, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'zihun-biantao', '字魂扁桃体', '字魂扁桃体.ttf', 'fonts/字魂扁桃体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 6, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'yanshi-xiehei', '演示斜黑体', '演示斜黑体.otf', 'fonts/演示斜黑体.otf', 'font/otf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 5, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'jingnan-junjun', '荆南俊俊体', '荆南俊俊体.otf', 'fonts/荆南俊俊体.otf', 'font/otf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 4, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'huxiaobo-nanshen', '胡晓波男神体', '胡晓波男神体.otf', 'fonts/胡晓波男神体.otf', 'font/otf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'douyin-meihao', '抖音美好体', '抖音美好体.otf', 'fonts/抖音美好体.otf', 'font/otf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (0, 'alimama-shuheiti', '阿里妈妈数黑体', '阿里妈妈数黑体.ttf', 'fonts/阿里妈妈数黑体.ttf', 'font/ttf', 0, 'platform', 'aliyun', 'https://zoco2.oss-cn-beijing.aliyuncs.com', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
