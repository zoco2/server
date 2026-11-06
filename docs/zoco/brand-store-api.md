# 品牌与门店接口

用户端商家接口。表结构见 [brand-store-schema.md](brand-store-schema.md)。

## 约定

路径前缀 `/api/`。控制器按 ThinkPHP 多级命名，例如 `merchant.brand`。

这些接口都要登录。请求头带 `token`，也可以用 `Authorization: Bearer <token>`，或名为 `token` 的 Cookie。未列入免登录名单，缺 token 或登录过期由登录中间件直接返回，控制器不再判断。

租户不作为参数传递。`BaseModel` 按当前请求的 `tenantId` 自动加上 `tenant_id`。查询再按当前登录用户的 `user_id` 限定，只能看到自己的品牌、门店和员工。

统一响应：

```json
{ "code": 1, "show": 0, "msg": "", "data": {} }
```

| 字段 | 说明 |
|---|---|
| `code` | `1` 成功，`0` 业务或参数失败，`-1` 登录超时 |
| `show` | `1` 时前端弹出 `msg`。写操作成功为 `1`，查询成功为 `0` |
| `msg` | 提示文案 |
| `data` | 查询结果。写操作成功时为空对象 |

参数错误、记录不存在、重名时 `code` 为 `0`，`show` 为 `1`。

列表分页使用项目的列表基类。参数是 `page_no`、`page_size`，默认每页 25 条，上限见 `config/project.php` 的 `lists.page_size_max`。返回：

```json
{
  "lists": [],
  "count": 0,
  "page_no": 1,
  "page_size": 25,
  "extend": []
}
```

图片字段传完整 `http://` 或 `https://` 地址。空字符串表示未上传。`create_time`、`update_time`、`delete_time` 由模型写入，不作为请求参数。未删除时 `delete_time` 为 `null`，删除时写入 Unix 秒。

## 品牌

### 列表

`GET /api/merchant.brand/lists`

| 参数 | 必填 | 说明 |
|---|---|---|
| `page_no` | 否 | 页码，默认 1 |
| `page_size` | 否 | 每页条数，默认 25 |
| `keyword` | 否 | 按品牌名称模糊搜索 |

`data` 为分页结构。`lists` 每项是基本信息：`id`、`name`、`industry`、`contact_name`、`contact_phone`、`contact_email`、`website`、`address`、`logo_url`、`cover_url`、`create_time`、`update_time`。四段资料正文只在详情里返回。

### 下拉

`GET /api/merchant.brand/choices`

无参数。`data` 为数组，每项只有 `id`、`name`，按 `id` 倒序。

### 详情

`GET /api/merchant.brand/detail`

| 参数 | 必填 | 说明 |
|---|---|---|
| `id` | 是 | 品牌 ID |

`data` 为单条品牌记录。不存在或不属于当前商家时返回「品牌不存在或无权限」。

### 创建

`POST /api/merchant.brand/add`

| 参数 | 必填 | 说明 |
|---|---|---|
| `name` | 是 | 品牌名称，1–100 字。同一商家未删除的品牌不能重名 |
| `industry` | 否 | 所属行业，最长 120 |
| `contact_name` | 否 | 联系人，最长 60 |
| `contact_phone` | 否 | 联系电话，最长 32 |
| `contact_email` | 否 | 联系邮箱 |
| `website` | 否 | 官方网站，须为 http(s) URL，最长 255 |
| `address` | 否 | 联系地址，最长 255 |
| `logo_url` | 否 | 品牌 Logo URL，最长 500 |
| `cover_url` | 否 | 品牌封面 URL，最长 500 |

成功：`msg` 为「创建成功」。重名时「品牌名称已存在」。

创建只写上面的基本信息。企业介绍、产品与服务、目标客户与地区、竞品与差异事实此时为空，不作为创建参数。

### 编辑

`POST /api/merchant.brand/edit`

参数与创建相同，并增加必填 `id`。只更新基本信息，不改四段资料正文。成功：`msg` 为「保存成功」。

### 保存一段资料

`POST /api/merchant.brand/saveSection`

创建完成之后，每次只保存下面四段中的一段。

| 参数 | 必填 | 说明 |
|---|---|---|
| `id` | 是 | 品牌 ID |
| `section` | 是 | `company_intro` 企业介绍，`products_services` 产品与服务，`target_customers` 目标客户与地区，`competitor_facts` 竞品与差异事实 |
| `content` | 是 | 正文，最长 5000 字。空字符串表示清空这一段 |

成功：`msg` 为「保存成功」。详情里这四段始终是字符串，未填写时为 `""`。

### 删除

`POST /api/merchant.brand/delete`

| 参数 | 必填 | 说明 |
|---|---|---|
| `id` | 是 | 品牌 ID |

软删除。该品牌下还有未删除门店时拒绝，提示「请先删除该品牌下的门店」。成功：`msg` 为「删除成功」。

## 门店

### 列表

`GET /api/merchant.store/lists`

| 参数 | 必填 | 说明 |
|---|---|---|
| `page_no` | 否 | 页码 |
| `page_size` | 否 | 每页条数 |
| `keyword` | 否 | 按门店名称模糊搜索 |
| `brand_id` | 否 | 只看某个品牌下的门店 |
| `status` | 否 | `1` 营业中，`0` 已停用 |

`lists` 每项是门店记录，字段与 `la_merchant_store` 一致。

### 下拉

`GET /api/merchant.store/choices`

| 参数 | 必填 | 说明 |
|---|---|---|
| `brand_id` | 否 | 传入时只返回该品牌下的门店 |

`data` 为数组，每项 `id`、`brand_id`、`name`。

### 详情

`GET /api/merchant.store/detail`

| 参数 | 必填 | 说明 |
|---|---|---|
| `id` | 是 | 门店 ID |

`data` 为门店记录，并额外返回 `location`。有坐标时格式为 `纬度,经度`，例如 `30.2592,120.1489`；未填坐标时为空字符串。不存在时返回「门店不存在或无权限」。

### 创建

`POST /api/merchant.store/add`

| 参数 | 必填 | 说明 |
|---|---|---|
| `brand_id` | 是 | 所属品牌，必须是当前商家的品牌 |
| `name` | 是 | 门店名称，1–100 字 |
| `status` | 否 | `1` 营业中，`0` 已停用。不传默认 `1` |
| `phone` | 否 | 门店电话，最长 32 |
| `contact_name` | 否 | 联系人，最长 60 |
| `business_hours` | 否 | 营业时间，最长 64 |
| `province` | 否 | 省 / 直辖市，最长 40 |
| `city` | 否 | 城市，最长 40 |
| `district` | 否 | 区 / 县，最长 40 |
| `address` | 否 | 详细地址，最长 255 |
| `location` | 否 | `纬度,经度`。纬度 -90–90，经度 -180–180。空则坐标存 `NULL` |
| `latitude` | 否 | 与 `longitude` 同时传。已传 `location` 时以 `location` 为准 |
| `longitude` | 否 | 同上 |
| `poi_id` | 否 | POI ID，最长 64 |
| `intro` | 否 | 门店简介，最长 1000 |
| `logo_url` | 否 | 门店 Logo URL，最长 500 |

所属品牌不存在时返回「所属品牌不存在或无权限」。只填了纬度或经度其中一个时返回「请同时填写纬度和经度」。成功：`msg` 为「创建成功」。

### 编辑

`POST /api/merchant.store/edit`

参数与创建相同，并增加必填 `id`。未传 `status` 时保持原营业状态。成功：`msg` 为「保存成功」。

### 删除

`POST /api/merchant.store/delete`

| 参数 | 必填 | 说明 |
|---|---|---|
| `id` | 是 | 门店 ID |

只软删除门店，不级联删除员工。成功：`msg` 为「删除成功」。

## 员工

员工不是登录账号。创建时不接收 `appid`、`openid`；员工绑定公众号通过员工绑定二维码完成。

### 列表

`GET /api/merchant.staff/lists`

| 参数 | 必填 | 说明 |
|---|---|---|
| `page_no` | 否 | 页码 |
| `page_size` | 否 | 每页条数 |
| `keyword` | 否 | 按姓名或手机号模糊搜索 |
| `store_id` | 否 | 只看某个门店下的员工 |

`lists` 每项是员工记录，字段与 `la_merchant_staff` 一致。未关联公众号时 `appid`、`openid` 为 `null`。

### 详情

`GET /api/merchant.staff/detail`

| 参数 | 必填 | 说明 |
|---|---|---|
| `id` | 是 | 员工 ID |

不存在时返回「员工不存在或无权限」。

### 公众号绑定二维码

`GET /api/merchant.staff/bindQrcode`

| 参数 | 必填 | 说明 |
|---|---|---|
| `id` | 是 | 员工 ID |

仅当前商家自己的未绑定员工可以创建二维码。成功返回 `qrcode_url` 和 `expire_seconds`（600 秒）。员工扫描并关注公众号，或已关注员工扫描二维码后，服务端绑定其 `openid`；员工或微信身份已绑定时不会覆盖现有记录。

### 同步绑定成功模板

`POST /api/merchant.staff/syncBindTemplate`

从 `config/merchant.php` 的 `staff_bind_notice` 读取模板标题和字段映射。先读取公众号现有模板并按标题复用；若不存在，只有配置了模板短编号和关键词时才会申请创建。微信返回的 `template_id` 保存到当前租户配置。本项目默认使用「订阅模板消息」的 `content` 字段发送员工、门店及绑定时间信息。

绑定完成后使用该模板向员工发送公众号模板消息。模板定义和字段映射保留在代码配置中；租户配置只保存微信返回的 `template_id`，不建立模板管理表。


### 测试发送绑定成功模板

`POST /api/merchant.staff/testBindNotice`

| 参数 | 必填 | 说明 |
|---|---|---|
| `id` | 是 | 员工 ID |

员工必须已经完成公众号绑定，即已有 `appid` 和 `openid`。接口复用绑定成功后的模板发送逻辑，向该员工发送一条测试模板消息，用于验证模板 ID、字段映射、公众号 access_token 和 openid 是否可用。

### 添加

`POST /api/merchant.staff/add`

| 参数 | 必填 | 说明 |
|---|---|---|
| `store_id` | 是 | 所属门店，必须是当前商家的门店 |
| `name` | 是 | 员工姓名，1–60 字 |
| `mobile` | 是 | 11 位手机号，以 1 开头 |

同一门店未删除员工的手机号不能重复，否则返回「该门店已有此手机号」。门店不存在时返回「所属门店不存在或无权限」。成功：`msg` 为「添加成功」。

### 编辑

`POST /api/merchant.staff/edit`

参数与添加相同，并增加必填 `id`。不修改 `appid`、`openid`。成功：`msg` 为「保存成功」。

### 删除

`POST /api/merchant.staff/delete`

| 参数 | 必填 | 说明 |
|---|---|---|
| `id` | 是 | 员工 ID |

软删除，并清空 `appid`、`openid`，以便该公众号身份以后可以重新绑定。成功：`msg` 为「删除成功」。

## 门店装修

控制器 `merchant.storeDesign`。图片用 `/upload/image` 返回的 `id` 和 URL，保存在草稿的 `files` 里。保存直接覆盖当前草稿。

独立装修页：`/merchant/store/{id}/decorate`。

### 读取

`GET /api/merchant.storeDesign/detail`

| 参数 | 必填 | 说明 |
|---|---|---|
| `store_id` | 是 | 门店 ID |

`data` 含 `store`、`config`、`revision`、`update_time`、`files`、`catalog`、`schemas`。从未保存时 `revision` 为 0。

### 保存

`POST /api/merchant.storeDesign/save`

| 参数 | 必填 | 说明 |
|---|---|---|
| `store_id` | 是 | 门店 ID |
| `config` | 是 | 装修 JSON |
| `files` | 否 | `[{id,url}]`，编辑器里用到的图 |

成功返回 `revision`、`update_time`。

### 摘要

`GET /api/merchant.storeDesign/summaries`

| 参数 | 必填 | 说明 |
|---|---|---|
| `brand_id` | 否 | 只看某个品牌下的门店 |

`data` 为数组，每项 `store_id`、`revision`、`update_time`。未装修过的门店 `revision` 为 0。

不做商家自定义装修模板，没有模板列表、保存和回收接口。
