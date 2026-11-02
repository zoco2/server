-- 模板租户补齐菜单配置，侧栏不显示。
SET NAMES utf8mb4;
SET @now := UNIX_TIMESTAMP();

INSERT INTO `la_tenant_system_menu`
(`tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 0, p.`id`, 'C', '菜单', 'el-icon-Operation', 100, 'auth.menu/lists', 'menu', 'permission/menu/index', '', '', 1, 0, 0, @now, @now
FROM `la_tenant_system_menu` p
WHERE p.`tenant_id` = 0
  AND p.`type` = 'M'
  AND p.`name` = '权限管理'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` m
    WHERE m.`tenant_id` = 0 AND m.`perms` = 'auth.menu/lists'
  )
LIMIT 1;

UPDATE `la_tenant_system_menu`
SET `is_show` = 0, `update_time` = @now
WHERE `tenant_id` = 0 AND `perms` = 'auth.menu/lists';

INSERT INTO `la_tenant_system_menu`
(`tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `create_time`, `update_time`)
SELECT 0, m.`id`, x.`type`, x.`name`, '', x.`sort`, x.`perms`, '', '', '', '', 0, 0, 0, @now, @now
FROM `la_tenant_system_menu` m
JOIN (
  SELECT 'A' AS `type`, '新增' AS `name`, 1 AS `sort`, 'auth.menu/add' AS `perms`
  UNION ALL SELECT 'A', '编辑', 1, 'auth.menu/edit'
  UNION ALL SELECT 'A', '删除', 1, 'auth.menu/delete'
  UNION ALL SELECT 'A', '详情', 0, 'auth.menu/detail'
) x
WHERE m.`tenant_id` = 0
  AND m.`perms` = 'auth.menu/lists'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` b
    WHERE b.`tenant_id` = 0 AND b.`perms` = x.`perms`
  );

UPDATE `la_tenant_system_menu`
SET `is_show` = 0, `update_time` = @now
WHERE `tenant_id` = 0
  AND `perms` IN ('auth.menu/add', 'auth.menu/edit', 'auth.menu/delete', 'auth.menu/detail');
