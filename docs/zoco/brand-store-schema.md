# 品牌与门店表设计

一个商家可以创建多个品牌。一个品牌下面有多家门店。一家门店下面有多名员工。

品牌到门店、门店到员工都是一对多，用外键列表示，不建中间表。

迁移：`upgrade/20261031_merchant_brand_store.sql`。同一脚本在 `public/upgrade/20261031_merchant_brand_store.sql`，并写入全新安装的 `public/install/db/like.sql`。

## 关系

```text
商家 (tenant_id, user_id)
  └── 品牌 la_merchant_brand
        └── 门店 la_merchant_store.brand_id
              └── 员工 la_merchant_staff.store_id
```

一家门店只填一个 `brand_id`。一名员工只填一个 `store_id`。

## 归属

每张表都有 `tenant_id` 和 `user_id`。`user_id` 是商家账号。员工表上的 `user_id` 也是商家，员工本身没有登录账号。

| 约定 | 做法 |
|---|---|
| 主键 | `bigint unsigned` 自增 |
| 时间 | Unix 秒。`delete_time = 0` 表示未删除 |
| 图片 | 直接存 URL。空字符串表示未上传 |
| 坐标 | 表单「纬度,经度」拆成 `latitude`、`longitude`。未填为 `NULL` |
| 字符集 | `utf8mb4`，InnoDB |

## 品牌 `la_merchant_brand`

对应「创建品牌」。

| 字段 | 类型 | 表单 | 说明 |
|---|---|---|---|
| `id` | bigint unsigned | — | 主键 |
| `tenant_id` | int unsigned | — | 租户 |
| `user_id` | int unsigned | — | 商家用户 |
| `name` | varchar(100) | 品牌名称，必填 | 同一商家未删除的品牌不能重名 |
| `industry` | varchar(120) | 所属行业 | 例如「精品咖啡 · 连锁门店」 |
| `contact_name` | varchar(60) | 联系人 | |
| `contact_phone` | varchar(32) | 联系电话 | 手机号、座机或客服电话 |
| `contact_email` | varchar(120) | 联系邮箱 | |
| `website` | varchar(255) | 官方网站 | 完整地址 |
| `address` | varchar(255) | 联系地址 | |
| `logo_url` | varchar(500) | 品牌 Logo | 图片 URL。方图，建议 400×400 以上，JPG / PNG / WebP，不超过 5MB |
| `cover_url` | varchar(500) | 品牌封面 | 图片 URL。横图，建议 1200×500 以上，JPG / PNG / WebP，不超过 5MB |
| `create_time` | int unsigned | — | |
| `update_time` | int unsigned | — | |
| `delete_time` | int unsigned | — | `0` 为未删除 |

索引：

- 主键 `id`
- 唯一 `uk_owner_name (tenant_id, user_id, name, delete_time)`
- 列表 `idx_owner (tenant_id, user_id, delete_time, id)`

## 门店 `la_merchant_store`

对应「创建门店」。`brand_id` 就是表单里的「所属品牌」。

| 字段 | 类型 | 表单 | 说明 |
|---|---|---|---|
| `id` | bigint unsigned | — | 主键 |
| `tenant_id` | int unsigned | — | 租户，与所属品牌一致 |
| `user_id` | int unsigned | — | 商家用户，与所属品牌一致 |
| `brand_id` | bigint unsigned | 所属品牌，必填 | `la_merchant_brand.id` |
| `name` | varchar(100) | 门店名称，必填 | 例如「山野咖啡 · 西湖店」 |
| `status` | tinyint unsigned | 营业状态 | `1` 营业中，`0` 已停用。默认 `1` |
| `phone` | varchar(32) | 门店电话 | |
| `contact_name` | varchar(60) | 联系人 | |
| `business_hours` | varchar(64) | 营业时间 | 例如 `09:00-22:00` |
| `province` | varchar(40) | 省 / 直辖市 | |
| `city` | varchar(40) | 城市 | |
| `district` | varchar(40) | 区 / 县 | |
| `address` | varchar(255) | 详细地址 | |
| `latitude` | decimal(10,7)，可空 | 位置坐标 | 纬度 |
| `longitude` | decimal(10,7)，可空 | 位置坐标 | 经度。例如 `30.2592,120.1489` |
| `poi_id` | varchar(64) | POI ID | |
| `intro` | varchar(1000) | 门店简介 | |
| `logo_url` | varchar(500) | 门店 Logo | 图片 URL |
| `create_time` | int unsigned | — | |
| `update_time` | int unsigned | — | |
| `delete_time` | int unsigned | — | `0` 为未删除 |

索引：

- 主键 `id`
- 商家列表 `idx_owner (tenant_id, user_id, delete_time, id)`
- 某品牌下的门店 `idx_brand (tenant_id, user_id, brand_id, delete_time)`

## 员工 `la_merchant_staff`

对应「新增员工」。`store_id` 就是表单里的「所属门店」。

| 字段 | 类型 | 表单 | 说明 |
|---|---|---|---|
| `id` | bigint unsigned | — | 主键 |
| `tenant_id` | int unsigned | — | 租户，与所属门店一致 |
| `user_id` | int unsigned | — | 商家用户，与所属门店一致 |
| `store_id` | bigint unsigned | 所属门店，必填 | `la_merchant_store.id` |
| `name` | varchar(60) | 员工姓名，必填 | |
| `mobile` | char(11) | 手机号，必填 | 11 位数字 |
| `appid` | varchar(64)，可空 | — | 公众号 AppID。创建员工时留空，以后关联公众号时写入 |
| `openid` | varchar(128)，可空 | — | 该公众号下的 OpenID。创建员工时留空 |
| `create_time` | int unsigned | — | |
| `update_time` | int unsigned | — | |
| `delete_time` | int unsigned | — | `0` 为未删除 |

索引：

- 主键 `id`
- 唯一 `uk_store_mobile (tenant_id, user_id, store_id, mobile, delete_time)`
- 唯一 `uk_oa_openid (tenant_id, appid, openid, delete_time)`
- 某门店下的员工 `idx_store (tenant_id, user_id, store_id, delete_time, id)`

同一门店里，未删除员工的手机号不能重复。同一个租户、同一个公众号下，未删除员工的 OpenID 也不能重复。两列都为空时不占这个唯一约束，所以未关联公众号的员工可以有多名。
