# NFC 码牌租户分配

平台在 NFC 列表中勾选未分配码牌，点击“分配给租户”，按名称或 ID 搜索有效租户并确认。列表展示分配状态、所属租户和分配时间。已经分配的码牌不能再次勾选；翻页或重新查询会清空勾选，弹窗打开时固定本次待分配 ID。

## 数据及校验

码牌表在第一阶段基础上新增 `tenant_id`、`store_id`、`tenant_assigned_admin_id` 和 `tenant_assigned_at`。`tenant_id IS NULL` 表示平台库存，非空表示已分配。`store_id` 为可空的无符号 bigint，记录绑定的门店 ID，尚未绑定时为 NULL。普通复合索引 `idx_nfc_store (tenant_id, store_id, id)` 支持一店多码，不对门店 ID 添加唯一约束。

分配只更新租户归属及操作记录，原编号和生成批次不变，`store_id` 保持未绑定。不添加 `user_id`；后续租户下用户通过自己的门店绑定码牌，门店绑定接口另外提交。

分配使用 `NfcCode` 和 `Tenant` 模型。服务在模型连接的事务中校验目标租户未停用、未删除，并锁住租户记录；只更新所选主键中 `tenant_id IS NULL` 的码牌。实际更新数量与去重后的所选数量必须一致，否则整批回滚，防止部分成功或覆盖其他请求已分配的归属。每次最多选 1000 块。

本阶段只开放平台接口，沿用平台登录及现有菜单权限机制，公共中间件、公共模型和登录请求方式均不改动。

## 接口和权限

- `GET /platformapi/merchant.nfc_code/lists`：保留原查询，新增 `status=unassigned|assigned` 筛选，记录增加 `tenant_id`、`tenant_name`、`tenant_assigned_at`。
- `GET /platformapi/merchant.nfc_code/tenantChoices?keyword=名称或ID`：最多返回 30 个有效租户，仅包含 `id` 和 `name`。需要权限 `merchant.nfc_code/tenantChoices`。
- `POST /platformapi/merchant.nfc_code/assignTenant`：请求 `{ "ids": [1, 2], "tenant_id": 3 }`，成功返回 `{ "quantity": 2 }`。需要权限 `merchant.nfc_code/assignTenant`。

操作管理员从登录态获取，不能由客户端指定。租户选项和分配分别登记菜单权限，管理员角色需要同时勾选“分配给租户”和“租户选项查询”。

## 部署及测试

第一阶段已部署的站点执行 `upgrade/20261117_merchant_nfc_code_tenant_assignment.sql`，然后一起发布服务端和 platform 页面。脚本逐项检测字段、索引和权限，支持重复执行，保留已有数据。此前已经执行过不含 `store_id` 的旧版第二阶段脚本，也可重新执行最新版补齐门店字段和索引。全新站点使用更新后的安装 SQL；尚未建表的旧站点先执行第一阶段 `20261117_merchant_nfc_code_generation.sql`，再执行本阶段迁移。

安装 Composer 依赖并启用 `pdo_sqlite` 后，运行 `php tests/nfc-code/tenant-assignment.php`。测试在内存库中覆盖生成回归、选择参数校验、有效租户搜索、软删除、分配审计、重复分配、整批冲突回滚和列表筛选。真实 MySQL 的并发竞争和迁移执行需在目标环境联调。
