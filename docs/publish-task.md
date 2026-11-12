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

- `content` 必填，最多 500 个字符。`platforms` 至少选择一个，分别对应抖音、小红书、快手。第一版保存平台选择，执行时只通过服务号发一条 `hello` 文本。
- `push_mode: immediate` 立即投递 MNS，仅执行一轮；无需填写定时字段。
- `push_mode: scheduled` 时，`push_time` 使用北京时间 `HH:mm`。`schedule_type: daily` 每天推送；`weekly` 还需 `weekday`（周一为 1，周日为 7）；`date_range` 还需 `start_date`、`end_date`（`YYYY-MM-DD`），含首尾最多 30 天，每天在指定时刻推送。
- 日期范围内没有未来执行时间时，任务直接返回 `ended`。消息迟到但仍在范围内，可执行当前轮次；已过结束日则跳过并结束。每次执行完成只安排下一次未来的轮次。
- 从员工列表发布时传入 `staff_ids`，它是包含 1 至 50 个正整数的数组。员工必须属于当前租户和商家，且已绑定服务号。重复 ID 会合并，每名员工对应一条任务，接口返回 `ids` 和 `count`。省略 `staff_ids` 时给当前用户创建一条任务，返回任务详情。

`GET /api/publish_task/lists?page_no=1&page_size=20` 查询当前用户创建的任务。任务详情包含 `status`（`scheduled`、`ended`、`failed`）、`next_run_at`、`last_run_at`（最近尝试的计划时刻）、`last_run_status` 和 `last_error`。员工任务还包含 `staff_id`。时间戳单位为秒。任务状态 `failed` 表示 MNS 消费重试耗尽；一轮微信发送失败记在 `last_run_status` 和 `last_error`，后续轮次仍会继续。若进程在发送阶段中断且无法确认结果，记录为 `unknown`，不会补发同一轮。

部署时先执行 `upgrade/20261112_publish_task.sql`，然后重启现有 `php think workerman` 消费进程。使用 `mns.default_queue`，队列默认延时须为 0，消息保留时间设为 7 天。单次最长延时为 6 天，超出时分段投递。任务保存下一条 MNS 消息的唤醒时间，消费时用它识别旧消息。接口按推送规则计算实际执行时间 `next_run_at`。

`hello` 通过微信公众号客服消息接口发送。实际送达需要租户配置或授权服务号、用户绑定服务号，且满足微信的客服消息发送窗口、权限与额度。
