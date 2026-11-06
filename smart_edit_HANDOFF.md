# 智能剪辑（阿里云 IMS 云剪辑）— 实施进度交接

> 本文件用于跨机器交接。新机器打开代码后，先读本文件，再读计划文件。
> 计划文件：`C:\Users\FangL\.claude\plans\elegant-growing-clover.md`（新机器路径不同，以下内容已完整复制关键部分）

---

## 0. 铁律（务必遵守）

1. **`server/app/apps/**` 是第三方安装的应用包，与我们无关**，绝不参考、复用、修改。
2. **不要用 `aigc_` 前缀命名新模块**（`aigc_*` 都属于 apps 那套第三方应用体系）。
3. **用户端接口在 `app/api`，租户后台接口在 `app/tenantapi`，两者不共用**，不要写"共用接口"。
4. **OSS 配置已经在租户后台配置好了**，不要再造一套 IMS/OSS 配置表，直接用 `StorageConfigService`。
5. 人工验证环境**没有 PHP CLI**，不要尝试部署/运行 PHP；用户会自己验。
6. 分阶段交付：第一轮只做「多视频合并」，做完停下等 review，不要一次堆完磁幕/贴纸/BGM。

---

## 1. 需求与决策

对接阿里云智能媒体服务（IMS / ICE 云剪辑）。分期：

- **第 1 轮（当前）**：多视频按顺序合并（merge）
- 第 2 轮：字幕（SubtitleTracks）
- 第 3 轮：贴纸 / 图片层（VideoTracks 中 Type=Image）
- 第 4 轮：背景音乐（AudioTracks + Volume）
- 之后：修剪区间、转场、变速、批量化

已确认：
- 模块名 `smart_edit`（自研）
- 第 1 轮**不收费**，只记录用量（表里预留计费字段，值 0，不建 `ai_consumption_log`）
- 素材与输出**必须阿里云 OSS**（IMS 需要公网可访问 MediaURL）
- 交付范围：**后端完整链路 + PC 最小页 + 租户后台任务列表**

---

## 2. 阿里云 IMS 要点（已核对官方文档）

调用链：
1. `SubmitMediaProducingJob`（ICE 2020-11-09）
   - `Timeline`（JSON 字符串）三选一参数之一
   - `OutputMediaTarget=oss-object`
   - `OutputMediaConfig={"MediaURL":"https://bucket.oss-cn-xxx.aliyuncs.com/path/out.mp4"}`
   - 可选 `UserData={"NotifyAddress":"https://..."}`
   - 返回 `JobId`
2. `GetMediaProducingJob(JobId)`
   - `MediaProducingJob.Status`：`Init` / `Queuing` / `Processing` / `Success` / `Failed`
   - 成功有 `MediaURL`、`Duration`；失败看 `Code` / `Message`

时间线（第 1 轮只用 VideoTracks）：
```json
{
  "VideoTracks": [{
    "VideoTrackClips": [
      { "MediaURL": "https://bucket.oss-cn-shanghai.aliyuncs.com/a.mp4", "In": 0, "Out": 5.0 },
      { "MediaURL": "https://bucket.oss-cn-shanghai.aliyuncs.com/b.mp4" }
    ]
  }]
}
```
- 同轨 clips 顺序相接；`TimelineIn/Out` 缺省自动计算
- `In/Out` 秒，4 位小数

后续轮次的轨道：
- 字幕：`SubtitleTracks[].SubtitleTrackClips[]`，`Type=Text` 需 `Content`/`TimelineIn/Out`/`FontSize`/`FontColor`/`Y`
- 贴纸：`VideoTracks` 中 `Type=Image` clip（`X/Y/Width/Height/Duration`）
- 音乐：`AudioTracks[].AudioTrackClips[]` + `Effects:[{"Type":"Volume","Gain":0.5}]`

**接入方式**：本轮**没有引入 SDK**，自己实现了 OpenAPI RPC 签名（ACS3-HMAC-SHA256），见 `ImsClient.php`。
如果发现签名不对，可以改成 `composer require alibabacloud/ice-20201109`。

**部署前提**（需人工确认）：
- IMS 已开通、有默认服务角色（OSS 读写）
- OSS bucket 与 IMS 同 region

---

## 3. 代码结构（全部新建，未碰 apps）

### 后端（`server/`）

| 文件 | 状态 | 说明 |
|---|---|---|
| `upgrade/20261103_smart_edit.sql` | ✅ 新建 | `la_smart_edit_task` + `la_smart_edit_task_item` |
| `upgrade/20261103_smart_edit_menu.sql` | ✅ 新建 | 租户后台菜单 template（tenant_id=0），父菜单「智能剪辑」+ 子菜单「剪辑任务」 |
| `app/common/model/smart_edit/SmartEditTask.php` | ✅ 新建 | 任务模型，含状态常量、RUNNING_STATUSES、TYPE_MERGE |
| `app/common/model/smart_edit/SmartEditTaskItem.php` | ✅ 新建 | 素材明细模型 |
| `app/common/service/smart_edit/SmartEditMaterialService.php` | ✅ 新建 | 读取租户 OSS 配置（StorageConfigService）、解析 region、拼 MediaURL、生成输出目标 |
| `app/common/service/smart_edit/ImsClient.php` | ✅ 新建 | ACS3-HMAC-SHA256 签名，submit/getJob，异常类 ImsApiException |
| `app/common/service/smart_edit/SmartEditTimelineBuilder.php` | ✅ 新建 | `buildMerge`，后续加 subtitle/sticker/bgm 分支 |
| `app/common/service/smart_edit/SmartEditService.php` | ✅ 新建 | generate / refresh / retry / cancel / delete / detail / decorateRows / formatTask |
| `app/common/command/SmartEditWorker.php` | ✅ 新建 | `php think smart-edit:worker [--once]`，轮询 RUNNING 任务 |
| `config/console.php` | ✅ 修改 | 注册 `smart-edit:worker` |
| `app/api/controller/smart_edit/GenerateController.php` | ✅ 新建 | `smart_edit.generate/index`（POST） |
| `app/api/controller/smart_edit/TaskController.php` | ✅ 新建 | `smart_edit.task/lists|detail|cancel|delete` |
| `app/api/lists/smart_edit/SmartEditTaskLists.php` | ✅ 新建 | 用户端列表，按 `user_id` 过滤，顺带刷新临期任务 |
| `app/tenantapi/controller/smart_edit/AdminTaskController.php` | ✅ 新建 | `smart_edit.admin_task/lists|detail|retry|delete` |
| `app/tenantapi/lists/smart_edit/SmartEditTaskLists.php` | ✅ 新建 | 租户后台列表，按 `tenant_id` 过滤，可按用户/状态/任务号筛选 |

> 注意：模型目录 `app/common/model/smart_edit/`、服务目录 `app/common/service/smart_edit/` 都**不是** `app/common/model/app/` 或 `app/common/service/app/`。

### 前端

| 文件 | 状态 | 说明 |
|---|---|---|
| `pc/api/smart-edit.ts` | ✅ 新建 | 用户端接口封装 |
| `pc/pages/ai/smart-edit.vue` | ✅ 新建 | 上传/排序/截取区间/提交/轮询/播放，独立页面 |
| `tenant/src/api/smart_edit.ts` | ✅ 新建 | 租户后台接口封装 |
| `tenant/src/views/smart_edit/task.vue` | ✅ 新建 | 任务列表 + 详情弹窗 + 重试/删除 |

**未改动**：`server/app/apps/**`、任何第三方安装包、`power_market`。

---

## 4. 数据表

`la_smart_edit_task` 关键字段：
- 标识：`id`、`tenant_id`、`user_id`、`task_no`（`SE`+时间+随机）
- 类型：`edit_type`（本轮只有 `merge`）
- 快照：`params_json`、`timeline_json`
- 上游：`provider`（`aliyun_ims`）、`region`、`provider_job_id`
- 输出：`output_target`（OSS key）、`output_uri`、`storage_scope/engine/domain`、`duration`、`cover_uri`
- 用量：`input_duration`
- 状态：`status`（pending/submitting/processing/success/failed/canceled）、`error_code`、`error_message`
- 轮询：`poll_attempts`、`next_poll_time`
- 预留：`tenant_cost_points`、`user_charge_points`、`app_task_id`、`consumption_id`
- 时间：`create_time`/`update_time`/`finish_time`/`delete_time`

`la_smart_edit_task_item`：
- `task_id`、`item_type`（`video`，后续加 `subtitle/sticker/audio`）、`sort`
- `source_uri`、`storage_*`、`in_sec`、`out_sec`、`duration`、`raw_meta`

---

## 5. 接口一览

### 用户端（`app/api`）
- `POST smart_edit.generate/index` — `{ edit_type:'merge', title?, clips:[{uri, in?, out?}] }`
- `GET  smart_edit.task/lists` — 当前用户任务
- `GET  smart_edit.task/detail?id=`
- `POST smart_edit.task/cancel`
- `POST smart_edit.task/delete`

### 租户后台（`app/tenantapi`）
- `GET  smart_edit.admin_task/lists` — 本租户全部任务，支持 `status` / `edit_type` / `user_id` / `task_no`
- `GET  smart_edit.admin_task/detail?id=`
- `POST smart_edit.admin_task/retry`（仅 `failed`）
- `POST smart_edit.admin_task/delete`

---

## 6. 当前状态与待验证

### 已完成
- 上述所有后端/前端文件已创建（见第 3 节）
- `config/console.php` 已注册 `smart-edit:worker`

### 未完成 / 待人工验证
1. **SQL 未执行**：`server/upgrade/20261103_smart_edit.sql` 和 `20261103_smart_edit_menu.sql` 需要人工在数据库执行
2. **PHP 语法未 lint**：本机无 PHP CLI，需要人工在有 PHP 的环境跑 `php -l`（重点检查 `ImsClient.php` 的签名拼接、`SmartEditService.php`）
3. **IMS ACS3 签名需实测**：`ImsClient::sign()` 仅按文档实现，第一次真实提交需要看返回（如果 400 SignatureDoesNotMatch，优先排查 canonical query 与 host 头）
4. **OSS 公网可访问性**：私有 bucket 走 `StorageSignedUrlService::resolve()` 生成 300s 签名 URL，如果排队超过 5 分钟会失效，需要留意
5. **前端未编译**：`pc` / `tenant` 都未跑 build，可能有类型/导入错误（例如 `feedback.msgError`、`normalizeFileUrl`、`ElInputNumber`）
6. **菜单只插入 tenant_id=0**：已有租户需要走「角色权限 → 菜单同步」或直接复制到对应 tenant_id

### 下一步（第 2 轮，等 review 后再开始）
- `SmartEditTimelineBuilder::buildSubtitle`
- `SmartEditService` 增加 `base_task_id`：基于已有任务的 `timeline_json` 追加 SubtitleTracks
- 前端字幕编辑器（时间轴 + 文本）
- `task_item.item_type='subtitle'`

---

## 7. 关键代码位置速查

- 素材 uri → MediaURL：`server/app/common/service/smart_edit/SmartEditMaterialService.php::mediaUrl()`
- OSS 配置读取：同上 `ossConfig()`（内调 `StorageConfigService::getConfiguredStorageConfig($tenantId,'aliyun')`）
- Timeline 构建：`SmartEditTimelineBuilder::buildMerge()`
- 提交/轮询：`ImsClient::submit()` / `ImsClient::getJob()`
- 任务生命周期：`SmartEditService::generate/refresh/retry/cancel/delete`
- 成片落地：`SmartEditService::markSuccess()`（写 `output_uri` + `TenantFile`）
- 轮询命令：`server/app/common/command/SmartEditWorker.php`

---

## 8. 验证清单（人工）

1. 执行两个 SQL
2. `php think smart-edit:worker --once` 能跑（无任务时正常退出）
3. 在租户后台「设置 → 存储」确认已配置阿里云 OSS 且为默认
4. PC 打开 `/ai/smart-edit`，上传 2 段视频 → 开始合并
5. 检查 `la_smart_edit_task`：`status=processing`、`provider_job_id` 非空、`timeline_json` 正确
6. 跑 worker 或等列表自动刷新，观察 `success`，`output_uri` 写入，`TenantFile` 多一条记录
7. 播放 `output_url`
8. 故意传不存在的 uri → `failed` 且错误可读

---

*生成时间：2026-11-03。转移机器后，在新机器用 `git status` 能看到以上全部新建文件。*