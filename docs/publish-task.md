# 任务发布接口

使用用户登录态。任务归属从登录态取租户 ID 和用户 ID。可以给自己或所属商家的员工安排服务号通知。

`POST /api/publish_task/create` 创建任务。每天定时推送的请求示例：

```json
{
  "content": "门店线上推广任务",
  "platforms": ["douyin", "xiaohongshu", "kuaishou"],
  "push_mode": "scheduled",
  "schedule_type": "daily",
  "push_time": "20:13"
}
```

- `content` 必填，最多 500 个字符。`platforms` 至少选择一个，分别对应抖音、小红书、快手。执行时通过服务号发送模板通知。
- `push_mode: immediate` 创建时即到期，仅执行一轮，无需填写定时字段。
- `push_mode: scheduled` 时，`push_time` 使用北京时间 `HH:mm`。`schedule_type: daily` 每天推送；`weekly` 还需 `weekday`（周一为 1，周日为 7）；`date_range` 还需 `start_date`、`end_date`（`YYYY-MM-DD`），含首尾最多 30 天，每天在指定时刻推送。
- 日期范围内没有未来执行时间时，任务直接返回 `ended`。消息迟到但仍在范围内，可执行当前轮次；已过结束日则跳过并结束。每次执行完成只安排下一次未来的轮次。
- 从员工列表发布时传入 `staff_ids`，它是包含 1 至 50 个正整数的数组。员工必须属于当前租户和商家，且已绑定服务号。去重后把员工 ID 存在同一条任务上。接口返回 `ids` 和 `count`，其中 `ids` 包含这条任务的 ID，`count` 是员工人数。省略 `staff_ids` 时通知当前用户，接口返回任务详情。

`GET /api/publish_task/lists?page_no=1&page_size=20` 查询当前用户创建的任务。任务详情包含 `status`（`scheduled`、`paused`、`ended`、`failed`）、`staff_ids`、`staff`、`next_run_at`、`last_run_at`（最近处理的计划时刻）、`last_run_status` 和 `last_error`。时间戳单位为秒。有后续轮次时继续排期。MNS 重试耗尽且没有后续轮次时，任务可以进入 `failed`。

`GET /api/publish_task/runs?task_id=1&page_no=1&page_size=20` 查询当前用户指定计划的执行记录，按执行记录 ID 倒序返回。每条记录包含 `tasksn`、`scheduled_at`、`status`、`create_time`、`update_time`、`staff` 和 `recipient_results`。`staff` 包含可查到的员工 ID、姓名和所属门店。发送结果以员工 ID 为键，键 `0` 表示计划创建人。

`POST /api/publish_task/pause` 和 `POST /api/publish_task/resume` 接收 `task_id`。暂停会跳过已入队但尚未开始的轮次，已开始的轮次处理完后再暂停。恢复时重新计算下一次时间，原周期已结束的计划变为 `ended`。

任务和执行记录分别存放在共用表 `publish_task` 和 `publish_task_run`，模型按 `tenant_id` 隔离数据。建表定义见 `upgrade/20261112_publish_task.sql`。

创建计划时保存首轮的 `next_run_at` 和 `tasksn`。启动 `php think workerman` 后，调度器查询到期计划，在事务内用 `lock(true)` 锁住对应计划行。已有相同 `tasksn` 的执行记录就跳过，否则创建 `pending` 记录并立即投递到 `mns.default_queue`。Job 消费时锁住同一行，同一用户的不同计划可以分别处理。

Job 开始处理时将记录改为 `running`，逐个发送并把每个人的结果写入 `recipient_results`。发送进度由独立连接提交，发送中断且无法确认结果时，该接收人标为 `unknown`，重试继续处理没有发送记录的人。本轮处理完记为 `completed`，超过结束日期记为 `skipped`，MNS 重试耗尽记为 `failed`。同时更新计划的 `next_run_at` 和 `tasksn`，没有下一轮时清空这两个字段。

任务通知复用员工列表「测试发送」的服务号模板。发给员工时，点击通知打开其所属门店的 公开门店页 `/storefront/{id}`。链接使用当前租户配置的访问域名，按租户 ID 访问时带上 `tenant_id`。仅通知创建人时没有对应员工门店，消息不带门店链接。
