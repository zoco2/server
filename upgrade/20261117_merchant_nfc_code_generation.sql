-- 第一阶段：平台统一生成 NFC 码牌库存。
-- 默认表前缀为 la_，手动执行时需与目标站点配置一致。
-- 此处只建平台库存，不提前引入租户归属、分配和激活字段；后续阶段需增量迁移。
-- IF NOT EXISTS 让重复执行跳过已有表，但不会修复或修改一个已有表的字段。
CREATE TABLE IF NOT EXISTS `la_merchant_nfc_code` (
  -- 内部自增主键，供库存排序与后续关联使用，不是面向商家的六位 code。
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  -- 业务编号固定六位；ASCII 二进制排序规则按字符精确比较，服务端统一生成大写。
  -- 必须同时含数字和字母的格式规则在服务层校验，唯一性由下方索引保证。
  `code` char(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- 同一次生成共用的批次号：NFC + 日期时间 + 随机后缀，便于整批查询与核对。
  `batch_no` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- 取自平台登录态中的管理员 ID，追溯生成来源，不接受客户端指定。
  `created_admin_id` int unsigned NOT NULL,
  -- 时间统一存 Unix 秒；同批写入使用同一时刻，前端显示时换算为毫秒。
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  -- 即使两个请求查库时都认为编号可用，也只能有一个成功插入，防止并发重复。
  UNIQUE KEY `uk_merchant_nfc_code` (`code`),
  -- 支持按批次过滤后按主键顺序分页，不将批次号本身设为唯一键。
  KEY `idx_batch` (`batch_no`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商家 NFC 码牌';

-- 在平台现有“租户管理”根目录下增加 NFC 列表菜单，C 表示页面菜单。
-- component 对应 platform 的 src/views/tenant/nfc-code/index.vue。
-- source_menu_key 是该页面的固定标识，用 NOT EXISTS 避免重跑时重复创建。
INSERT INTO `la_system_menu`
  (pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT p.id,'C','NFC 码牌','el-icon-Tickets',91,'merchant.nfc_code/lists','nfc-code','tenant/nfc-code/index','','',0,1,0,'','core','core_platform_merchant_nfc_code',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` p
WHERE p.pid=0 AND p.type='M' AND p.paths='tenant'
  AND NOT EXISTS (SELECT 1 FROM `la_system_menu` e WHERE e.source_menu_key='core_platform_merchant_nfc_code');

-- 在 NFC 页面下登记“批量生成”操作权限，A 表示按钮或接口操作。
-- perms 同时对应前端 v-perms 和服务端权限校验，is_show=0 表示不作为导航菜单展示。
-- 通过父页面固定标识查找 pid，以权限字符串判重，重复执行不生成第二个按钮。
-- 本脚本登记权限项；需要使用的普通管理员仍需由平台给其角色授权。
INSERT INTO `la_system_menu`
  (pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT p.id,'A','批量生成','',0,'merchant.nfc_code/generate','','','','',0,0,0,'','core','core_platform_merchant_nfc_code_generate',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` p
WHERE p.source_menu_key='core_platform_merchant_nfc_code'
  AND NOT EXISTS (SELECT 1 FROM `la_system_menu` e WHERE e.perms='merchant.nfc_code/generate');
