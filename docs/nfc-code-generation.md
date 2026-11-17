# NFC 码牌第一阶段

平台管理员可查询码牌库存，每次生成 1 至 1000 个编号。编号固定六位，使用数字和大写字母，且至少包含一个数字和一个字母。生成时通过模型批量查库判重，数据库唯一索引兜底并发冲突，整批在同一事务中写入。

## 接口

- `GET /platformapi/merchant.nfc_code/lists`：`page_no`、`page_size`，可按 `code`、`batch_no` 查询。返回 `count`、`lists`、`page_no`、`page_size`，记录包含 `id`、`code`、`batch_no`、`created_admin_id`、`create_time`（Unix 秒）。
- `POST /platformapi/merchant.nfc_code/generate`：`quantity` 为 1 至 1000 的整数，返回 `batch_no` 和 `quantity`。

接口使用平台登录及菜单权限，分别为 `merchant.nfc_code/lists`、`merchant.nfc_code/generate`。库存通过继承 `BaseModel` 的 `NfcCode` 模型访问，使用共享表，不按租户分表。

## 部署

现有站点执行 `upgrade/20261117_merchant_nfc_code_generation.sql`（默认表前缀 `la_`），同步发布 platform 前端，给需要使用的管理员角色授予 NFC 列表和批量生成权限。新装 SQL 包含相同表结构及菜单。

本阶段只包含平台生成和查询。原完整 NFC 分支保留供后续拆分，租户分配、用户分配和门店激活需另行提交，并提供从本阶段表结构升级的增量迁移；不能仅重复执行旧版完整建表脚本。

## 测试

安装 Composer 依赖并启用 `pdo_sqlite` 后，运行 `php tests/nfc-code/generation.php`。测试只使用 SQLite 内存库，不加载应用数据库配置，覆盖数据库碰撞补码、批内去重、数量上限、查询分页和跨写入分块的事务回滚。MySQL 唯一键冲突及死锁重试仍需在 MySQL 环境联调。
