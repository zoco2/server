# 用户文件分组

用户给自己上传的图片、视频建文件夹。用户之前没有分组数据，上传文件的 `cid` 都是 `0`，所以单独建一张用户分组表，后台分组表和后台分组代码都不改。

本方案只覆盖 `la_tenant_file` 里 `source = 1` 且 `upload_origin = local` 的用户上传。工作台里生成的作品仍用现有标签，不进这套文件夹。

## 两套分组

| | 后台 | 用户 |
|---|---|---|
| 分组表 | `la_tenant_file_cate` | `la_user_file_cate` |
| 文件 | `la_tenant_file.source = 0` | `la_tenant_file.source = 1` |
| 文件上的指针 | `cid` 指向后台分组 | `cid` 指向用户分组，`0` 为未分组 |

`cid` 仍写在原来的文件行上，不给文件表加字段。同一个数字在两边不是同一个分组：后台只拿 `source = 0` 的 `cid` 去查 `la_tenant_file_cate`，用户只拿自己的 `source = 1` 文件去查 `la_user_file_cate`。

后台列表、移动、删除都带 `source = 0`。用户分组写在另一张表，后台分类列表读不到，后台按 `pid` 删分组也走不到。现有用户文件全是未分组，没有旧分组要迁移。

## 表

新建 `la_user_file_cate`。

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | int unsigned 自增 | 主键 |
| `tenant_id` | int | 租户。由 `BaseModel` 租户范围写入和过滤 |
| `user_id` | int unsigned | 用户。接口写入并作为查询条件 |
| `pid` | int unsigned 默认 0 | 父分组，`0` 为顶级 |
| `type` | tinyint unsigned | `10` 图片，`20` 视频，`30` 文件 |
| `name` | varchar(32) | 分组名称 |
| `create_time` | int unsigned NULL | 创建时间 |
| `update_time` | int unsigned NULL | 更新时间 |
| `delete_time` | int unsigned NULL | 为空表示未删除，模型使用 `SoftDelete` |

索引：`(tenant_id, user_id, type, id)`、`(pid)`。

迁移写 `upgrade/` 与 `public/upgrade/` 各一份，并写入全新安装的 `public/install/db/like.sql`。不改 `la_tenant_file`，不改 `la_tenant_file_cate`。

`tenant_id` 靠模型全局范围，用户接口不传 `tenantId`，也不手写 `where tenant_id`。`user_id` 没有全局范围，查询和写入都要带当前用户。

## 用户接口

控制器放用户端。登录沿用 `LoginMiddleware`，控制器里不再判断登录。列表用 `dataLists`。删除分组走模型 `SoftDelete`。

| 方法 | 路径 | 作用 |
|---|---|---|
| GET | `/api/file/listCate` | 当前用户、指定 `type` 的分组树 |
| POST | `/api/file/addCate` | 新建分组。参数 `type`、`pid`、`name` |
| POST | `/api/file/editCate` | 改名称 |
| POST | `/api/file/delCate` | 删除分组及子分组 |
| POST | `/api/file/move` | 把自己的文件移入分组或移出 |
| POST | `/api/workspace_asset/upload` | 上传图片或视频。表单字段 `cid` 指定分组，不传或 `0` 为未分组 |
| POST | `/api/upload/image` | 上传图片，`cid` 对应类型 `10` |
| POST | `/api/upload/video` | 上传视频，`cid` 对应类型 `20` |
| POST | `/api/upload/file` | 上传文件，`cid` 对应类型 `30` |

名称最长 20 个字符。分组树只查 `la_user_file_cate`。上传与后台一样，在同一次请求里带 `cid`。`cid` 大于 `0` 时，分组必须属于当前用户，且 `type` 与文件一致。

`WorkspaceAssetService::uploads` 的返回补上 `cid`，并接受可选 `cid`：不传查全部，`0` 查未分组，大于 `0` 查该分组及其子分组。筛选时子分组 id 只从 `la_user_file_cate` 取。收藏、标签、回收站不变。

## 归属规则

以下不满足时拒绝，不写入。

- 父分组属于当前用户，且 `type` 与新建分组相同。`pid = 0` 表示顶级。
- 上传和移动时 `cid = 0`，或目标分组属于当前用户且 `type` 与文件一致。图片为 `10`，视频为 `20`，webm 为 `30`。
- 被移动的文件必须是当前用户的上传：`source = 1`、`source_id` 为当前用户、`upload_origin = local`。
- 编辑、删除只处理当前用户在 `la_user_file_cate` 里的分组。

删除用户分组时，把这些分组下、且属于当前用户的文件 `cid` 改回 `0`，文件本身不删。更新文件时必须带 `source = 1` 和当前 `source_id`，避免同一个 `cid` 数字碰到后台文件。

## 不改动

- 后台 `FileController`、`FileLogic`、`FileCateLists` 保持原样。
- `la_tenant_file_cate` 和平台端 `FileCate` 保持原样。
- `la_user_asset_labels` 和素材标签保持原样。
- 生成结果、画布作品不建文件夹。
