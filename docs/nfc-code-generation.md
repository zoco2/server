# NFC 码牌第一阶段

平台管理员可查询码牌库存，每次生成 1 至 1000 个编号。编号固定六位，使用数字和大写字母，且至少包含一个数字和一个字母。生成时通过模型批量查库判重，数据库唯一索引兜底并发冲突，整批在同一事务中写入。

## 接口

- `GET /platformapi/merchant.nfc_code/lists`：`page_no`、`page_size`，可按 `code`、`batch_no` 查询。返回 `count`、`lists`、`page_no`、`page_size`，记录包含 `id`、`code`、`batch_no`、`created_admin_id`、`create_time`（Unix 秒）。
- `POST /platformapi/merchant.nfc_code/generate`：`quantity` 为 1 至 1000 的整数，`tenant_id` 可选。返回 `batch_no`、`quantity` 和实际归属 `tenant_id`；未选租户时为 NULL。

接口使用平台登录及菜单权限，分别为 `merchant.nfc_code/lists`、`merchant.nfc_code/generate`。库存通过继承 `BaseModel` 的 `NfcCode` 模型访问，使用共享表，不按租户分表。

## 生成与查询流程

1. 平台控制器从登录态读取管理员 ID，把数量原始值交给服务校验。数量支持整数及整数字符串，不会把布尔值或小数直接强制转换成整数。
2. 服务生成一次请求共用的批次号，再收集六位随机编号。批内通过集合去重，按每组 250 个查询已有库存，只补足差额；补码最多二十轮，避免极端情况下无限循环。
3. 候选编号按字符排序后写入，每次最多 250 条，所有分块共享模型连接的同一个事务。分块失败会回滚整批；MySQL 唯一键冲突（1062）或死锁（1213）会重新生成并重试，总共最多五次。其他存储异常直接向上抛出。
4. 成功响应返回批次号和生成数量。平台页面清除旧编号条件，并按本批次从第一页重新查询，方便核对生成结果；失败则保留弹窗数量供管理员重试。

生成弹窗支持可选租户。选择时会在同一生成事务内校验租户并写入归属，不选时保持平台库存；详细权限和行为见 [租户分配](nfc-code-tenant-assignment.md)。

库存查询对编号及批次使用精确匹配。编号先去除两端空白并转大写，批次去除两端空白。每页最多 100 条，按主键倒序显示，总数和列表使用相同的筛选条件。记录时间以 Unix 秒返回，前端换算成毫秒后展示。

## 部署

现有站点执行 `upgrade/20261117_merchant_nfc_code_generation.sql`（默认表前缀 `la_`），同步发布 platform 前端，给需要使用的管理员角色授予 NFC 列表和批量生成权限。新装 SQL 包含相同表结构及菜单。

第一阶段只包含平台生成和查询。租户分配的增量迁移和操作说明见 [租户分配](nfc-code-tenant-assignment.md)。后续由租户下用户通过门店直接绑定，不单独分配给用户，也不在码牌表中添加 `user_id`；门店绑定需另行提交。

## 测试

安装 Composer 依赖并启用 `pdo_sqlite` 后，运行 `php tests/nfc-code/generation.php`。测试只使用 SQLite 内存库，不加载应用数据库配置，覆盖数据库碰撞补码、批内去重、数量上限、查询分页和跨写入分块的事务回滚。MySQL 唯一键冲突及死锁重试仍需在 MySQL 环境联调。
