# GEO 模块 —— 交接文档

> 最后更新：2026-11-03
> 项目：likeadmin 多租户（ThinkPHP 8）+ Nuxt3 PC 端
> 分支：`feature/merchant-brand-store`
> 仓库：`git@github.com:zoco2/server.git`

---

## 一、重要前提（必读）

1. **`app/apps/aigc_geo/` 是第三方安装型扩展应用，与本项目无关，不要动它。**
   PC 端 `/app/aigc_geo` 是静态演示页，同样**不要动**，保持原样即可。
2. **本项目自研的 GEO 功能，PC 端路径是 `/godata`**，侧栏入口叫「GEO发稿」。
3. 交付方式：**分阶段做完 → commit → push → 用户部署验证 → 再下一阶段**。
   不要一次堆大量代码，用户要能逐步 review。
4. 写库时要注意：模型 `$json` 声明的字段（如 `price_types`）**必须用声明名写入**，
   写数据库列名 `price_type_json` 会触发 ThinkPHP Builder 的数组更新分支，报 `Undefined array key 0`。
5. 模型查询**不要手动加 `tenant_id` 条件**，`BaseModel` 全局作用域已注入。
6. CLI 里没有租户上下文，`ConfigService` 会失败，需要模拟：
   `request()->tenantId / tenantSn / source = AdminTerminalEnum::TENANT / tenantResolveMode`
   （已封装在 `GeoMediaSync::bindTenant()`）。
7. ThinkPHP console 保留了 `--quiet`，自定义静默参数用 `--no-progress`。

---

## 二、整体架构

```
PC 前台 (Nuxt3, /godata)               租户后台 (tenantapi)
        │                                     │
        ▼                                     ▼
app/api/controller/geo/*          app/tenantapi/controller/geo/*
        │                                     │
        └──────────► app/common/service/geo/* ◄──────────┘
                          │
                          ├── AigcGeoManuscriptService   海讯社 HTTP 客户端（底层，已存在）
                          ├── GeoMediaSyncService        资源同步 / 倍率 / 租户配置读取
                          ├── GeoMediaCatalogService     前台资源广场（只出售价）
                          ├── GeoArticleService          稿件 CRUD + 预览
                          └── GeoOrderService            投稿单 推送/查询/确认/反馈
                          │
                          ▼
                海讯社 api.haipress.com（无回调，只能轮询）
```

---

## 三、已完成阶段

### S1 —— 媒体资源同步 ✅（已 push）

| 文件 | 说明 |
|---|---|
| `upgrade/20261103_geo_media_article_order.sql` | 建 `la_geo_media` / `la_geo_article` / `la_geo_order` / `la_geo_order_log` |
| `app/common/model/geo/GeoMedia.php` | `$json = ['price_types']`；`sale_price` **不落库**，getter 实时算 `costprice × ratio` |
| `app/common/service/geo/GeoMediaSyncService.php` | `syncType / syncAll / markup / tenantConfig / suggestSalePrice` |
| `app/common/command/GeoMediaSync.php` | 命令 `geo:media-sync`，参数 `--tenant --type --limit --state --no-progress` |
| `app/tenantapi/controller/geo/MediaController.php` | 后台：lists/types/sync/update/refreshStates/saveMarkup |
| `app/tenantapi/logic/geo/GeoMediaLogic.php` | 后台逻辑层 |
| `app/tenantapi/lists/geo/GeoMediaLists.php` | 后台列表（价格区间按 成本×倍率 在 SQL 里算） |

**关键设计：售价不落库。** 生效倍率 = 行级 `price_ratio`（>0 时）否则租户默认 `geo_manuscript.markup`。
调倍率不需要回写数据。`la_geo_media` 里**没有 `sale_price` 列**（如早期部署过，需 `ALTER TABLE ... DROP COLUMN sale_price`）。

**实测命令：**
```bash
php think geo:media-sync --tenant=1 --type=1
php think geo:media-sync --tenant=1            # 全部类型
php think geo:media-sync --tenant=1 --limit=100  # 试跑
```

---

### S2 —— 稿件 + 免登录预览 ✅（已 push，commit `9a71a1b`）

| 文件 | 说明 |
|---|---|
| `app/common/model/geo/GeoArticle.php` | 软删除，`autoWriteTimestamp='int'` |
| `app/common/service/geo/GeoArticleService.php` | CRUD + `preview(token)` + 正文清洗（去 `<script>`、`on*`） |
| `app/api/controller/geo/ArticleController.php` | 前台：lists/detail/save/delete + **`preview` 免登录直出 HTML** |
| `app/tenantapi/controller/geo/ArticleController.php` | 后台只读：lists/detail/delete |
| `app/tenantapi/lists/geo/GeoArticleLists.php` | 后台列表 |

**关键设计：** 海讯社建单时 `content` 传的是 **URL**，对方会来抓取正文。
所以预览页必须：服务端直出 HTML、免登录、公网可达、不依赖 JS。
地址格式：`{租户域名}/api/geo.article/preview?token=xxx`，token 存 `preview_token`，编辑稿件时不换 token。

⚠️ **S2 用户当时没做端到端验证**（原话：「s2 暂时不测试直接开始 s3」），预览页只验证了代码层面。

---

### S3 —— 海讯社投稿 ✅（已 push，commit `6a3a316`）

| 文件 | 说明 |
|---|---|
| `app/common/service/geo/GeoOrderService.php` | create / push / sync / syncDue / process / detail / lists |
| `app/common/command/GeoOrderSync.php` | 命令 `geo:order-sync --limit=100 --no-progress` |
| `app/api/controller/geo/OrderController.php` | 前台：create/lists/detail/confirm/feedback |
| `app/tenantapi/controller/geo/OrderController.php` | 后台：lists/detail/push/sync/syncDue/confirm/feedback |
| `app/tenantapi/lists/geo/GeoOrderLists.php` | 后台列表 |
| `config/console.php` | 注册 `geo:order-sync` |

**状态映射**（`GeoOrder::STATUS_*`）：

| 值 | 含义 | 说明 |
|---|---|---|
| -1 | 待推送 | 本地态，尚未调海讯 |
| 0 | 待安排 | 海讯已受理 |
| 1 | 已安排 | |
| 2 | 拒稿 | 看 `fail_reason` |
| 3 | 发布成功待确认 | **只有这个状态能确认/反馈** |
| 4 | 反馈处理中 | |
| 5 | 已完成 | |
| 100 | 订单不存在 | |

**关键约束：**
- 海讯建单 `price` **必须与海讯最新价完全相等**，否则 `priceError` 直接拒稿。
  `push()` 推送前会从本地 `la_geo_media` 刷新一次成本价再发。
- `orderExist` → 转成 `STATUS_PENDING` 继续轮询（说明已推送过）。
- 资源类/价格类错误 → `retryable=0`，不自动重试，需先跑 `geo:media-sync` 刷新价格再手动重推。
- **海讯无回调**，全靠 `geo:order-sync` 轮询，`QUERY_INTERVAL=300s` 节流。
- flag=3 超 24h 未被确认，海讯会自动通过 —— 所以定时任务必须跑起来。

**cron 建议：**
```
*/5 * * * * cd /path && php think geo:order-sync --no-progress
```

---

### S4 —— PC 前台页面 🚧（**已完成，未 commit**）

| 文件 | 状态 |
|---|---|
| `server/app/common/service/geo/GeoMediaCatalogService.php` | ✅ 新建，未提交 |
| `server/app/api/controller/geo/MediaController.php` | ✅ 已重写（原版有 bug），未提交 |
| `server/app/api/controller/geo/ArticleController.php` | ✅ 小改（save 去掉 show=0），未提交 |
| `pc/api/geo.ts` | ✅ 新建，未提交 |
| `pc/pages/godata/index.vue` | ✅ 新建，未提交 |
| `pc/components/geo/geo-page.scss` | ✅ 新建，未提交 |
| `pc/components/ai-workspace/InspirationAigcHome.vue` | ✅ 加「GEO发稿」导航项 + 顶栏标题，未提交 |
| `pc/composables/useInspirationWorkspaceActivePage.ts` | ✅ `/godata` → `godata`，未提交 |

**`/godata` 页面三个 Tab：**

1. **资源广场**（免登录可看）
   - 类型 Tab（带数量）、关键词搜索、排序（价格/推荐）、分页
   - 卡片：名称、类型徽章、地区/分类/新闻源/包收录标签、备注、价格、去投稿
   - 短视频/微信/微博（`GEO_MULTI_PRICE_TYPES = [12,13,14]`）显示「¥最低价 起」，投稿时选价格类别
2. **我的稿件**
   - 列表 + 搜索，操作：预览（新窗口开 `preview_url`）/ 编辑 / 删除
   - 编辑器是 **textarea 写 HTML**（不是富文本），S2 没做富文本
3. **我的投稿单**
   - 列表 + 关键词/状态筛选
   - 状态点颜色、拒稿原因、发布链接
   - flag=3 时出现「确认发布」「反馈修改」

**前台接口（`/api/geo.media/*` 免登录）：**

| 接口 | 说明 |
|---|---|
| `GET /api/geo.media/types` | 类型字典 + 各类型数量 + 价格区间 |
| `GET /api/geo.media/lists` | 分页：`resource_type / region / keyword / newsresource / searchcollint / weekendpublish / min_price / max_price / sort / page_no / page_size` |
| `GET /api/geo.media/detail?id=` | 详情 |

⚠️ **前台绝不返回 `costprice` / `price_ratio` / `sourceid` / `detail_json`**。
`GeoMediaCatalogService::publicRow()` 统一做这层裁剪，`price_types` 里也只留 `type / sale_price`。

---

## 四、S4 待办（下一步做什么）

### 立即要做

1. **提交 S4 代码并 push**（当前都在工作区未提交）：
   ```bash
   cd D:/code/meituo/server
   git add app/api/controller/geo/MediaController.php \
           app/api/controller/geo/ArticleController.php \
           app/common/service/geo/GeoMediaCatalogService.php
   git commit -m "feat(geo): S4 PC 前台资源广场接口"
   git push

   cd D:/code/meituo/pc
   git add api/geo.ts pages/godata components/geo \
           components/ai-workspace/InspirationAigcHome.vue \
           composables/useInspirationWorkspaceActivePage.ts
   git commit -m "feat(geo): S4 PC 前台 GEO 发稿页 (/godata)"
   git push
   ```
   ⚠️ server 仓库里有**其他人在改的 smart_edit 文件**（`app/api/controller/smart_edit/` 等），
   **不要一起提交**，只 add 上面列出的 geo 文件。

2. **端到端验证 S2/S3/S4**（S2、S3 用户都没实际测过）：
   - 后台同步资源 → `/godata` 能看到媒体列表
   - 写稿 → 复制 `preview_url` 用无痕窗口打开，确认正文完整
   - 选一个最便宜的软文资源投稿 → 看订单状态
   - `php think geo:order-sync` 跑起来，看 0→1→3
   - flag=3 时点「确认发布」→ 5

### 后续阶段

| 阶段 | 内容 |
|---|---|
| **S5** | GEO 诊断收录：对接 `https://118.89.28.187/api/v1`，`GeoDiagProvider` + `geo:diag-poll` 命令 + PC 诊断页 `/godata/diagnosis`（已实现） |
| **S6** | 租户菜单 SQL + 权限点（`la_tenant_menu`），GEO 设置页合并（海讯 key、加成倍率） |

**S5 接口要点（已按开放文档 v1.11 实现）：**
- 认证：`Authorization: Bearer API_KEY`，代码读取 `geo_diag.api_key` 或环境变量 `GEO_DIAG_API_KEY`，不要把真实 key 提交进仓库。
- Base URL 默认 `https://118.89.28.187/api/v1`，可用 `geo_diag.base_url` 覆盖；IP HTTPS 默认关闭证书校验，可用 `geo_diag.ssl_verify=true` 打开。
- `POST /api/v1/runs` 支持批量 `items`，**必带 `Idempotency-Key`**，本地按诊断批次生成稳定幂等键。
- 轮询：`GET /batches/{batchId}/status`，终态后读取 `GET /batches/{batchId}?page=` 和 `GET /runs/{runId}/report`。
- 平台和计费：`GET /platforms`；省份：`GET /regions`（只允许 Web 平台）；余额：`GET /usage`。
- PC 入口：侧栏「GEO诊断」→ `/godata/diagnosis`，可选品牌、平台、重复次数、截图、对话链接、省份，提交后页面轮询详情。

**S5 表结构：**
- 老库先执行 `upgrade/20261104_geo_diagnosis.sql` 的，继续执行 `upgrade/20261107_geo_diagnosis_runtime.sql` 补齐运行时字段。
- 新安装库已把 `la_geo_diag_batch` / `la_geo_diag_run` / `la_geo_diag_sample` 写入 `public/install/db/like.sql`。
- 轮询命令：`php think geo:diag-poll --limit=50 --no-progress`，建议 cron 每分钟一次。

---

## 五、已知问题 / 技术债

| # | 问题 | 影响 | 建议 |
|---|---|---|---|
| 1 | 稿件编辑器是 textarea 写 HTML | 用户不会写 HTML | 后续换成后台同款富文本编辑器（复用 `UploadController`） |
| 2 | S2 预览页未实测 | 海讯抓取失败会直接拒稿 | 先用无痕窗口验证 |
| 3 | `GeoOrderService::create()` 不扣费 | 用户下单 0 成本 | 计划表里有 `power_log_id` 预留字段，S5 之后接算力扣费 |
| 4 | 前台无「取消订单」 | 海讯不支持取消 | 无解，只能等自然结束 |
| 5 | 媒体广场地区筛选已从 UI 移除 | 后端 `/region` 参数还在 | 因为地区值太杂（每个资源一个值），后端参数保留备用 |
| 6 | `pc/api/geo.ts` 用 `as Promise<T>` | $$request 无泛型类型 | 与项目现有写法一致，可接受 |

---

## 六、数据库

迁移放在 `upgrade/`（**不是** `public/upgrade/`）：

- `upgrade/20261103_geo_media_article_order.sql` —— S1/S2/S3 共 4 张表
- `upgrade/20261104_geo_diagnosis.sql` —— S5 诊断基础 3 张表
- `upgrade/20261107_geo_diagnosis_runtime.sql` —— S5 诊断运行时字段与新表兼容建表

**部署时手动执行**，本项目没有自动迁移。

---

## 七、命令速查

```bash
# 资源同步（必须先配好海讯账号：租户后台 → 设置 → 海讯社）
php think geo:media-sync --tenant=1 --type=1
php think geo:media-sync --tenant=1

# 投稿单状态同步（必须挂 cron，每 5 分钟）
php think geo:order-sync --no-progress

# GEO 品牌诊断状态同步（建议每分钟）
php think geo:diag-poll --limit=50 --no-progress

# 查看命令列表
php think list | grep geo
```

---

## 八、前端入口

| 入口 | 路径 | 说明 |
|---|---|---|
| 「GEO发稿」（侧栏工具组） | `/godata` | **自研功能页** |
| 「GEO诊断」（侧栏工具组） | `/godata/diagnosis` | **自研品牌诊断页** |
| 「GEO营销」（侧栏工具组） | `/app/aigc_geo` | 第三方静态演示页，不动 |

新增入口改了两个文件（各 1~2 行）：
- `pc/components/ai-workspace/InspirationAigcHome.vue` —— `platformNav` 数组 + `headerPageTitle`
- `pc/composables/useInspirationWorkspaceActivePage.ts` —— `resolveInspirationActivePageFromRoute`

---

## 九、环境说明

- 开发机 **没有 PHP CLI**，无法本地 `php -l` 语法检查，只能在服务器上跑。
- PC 端可以 `npx eslint <file>` 检查（`npx eslint --fix` 会给整个文件重排 prettier 格式，
  **改已有文件时不要用 --fix**，会把无关代码全部重排）。
- 服务器部署后需要用 SSH 执行命令验证。