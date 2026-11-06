# Agent LLM 流式对话与 reconnect

## 当前架构

Agent LLM 采用异步任务模式，而不是让一次 PHP 请求直接调用模型并持续返回：

1. `POST /app.aigc_llm.agent/submit` 创建数据库任务并返回 `run_id`。
2. `php think home-agent:worker` 领取任务并调用 LLM。
3. `POST /app.aigc_llm.agent/stream` 通过 SSE 订阅任务状态和回复。

因此，SSE 接口只负责订阅，不负责执行模型。

## reconnect 的含义

`server/app/common/service/app/aigc_llm/agent/AgentStreamService.php` 将一次 SSE 监听限制为 20 秒。到期后发送 `event: reconnect` 并结束当前请求，这是主动续接信号，不代表模型请求失败。

## 当前发现的问题

PC 前端 `PC/pages/app/aigc_llm.vue` 处理了 `run.snapshot` 和 `error`，但没有处理 `reconnect`。所以收到续接事件后连接关闭，前端没有再次订阅。

应在收到 `reconnect` 后使用同一个 `run_id` 重新调用 `streamHomeAgentRun()`，直到任务进入终态。

## Worker 要求

服务器必须持续运行：

```bash
php think home-agent:worker
```

若 Worker 未运行，任务会停留在 `queued` 或 `running`；前端即使重新连接，也只能重复读取未完成状态。

## 结论

- `reconnect` 每 20 秒出现是后端当前设计。
- PC 前端缺少自动续接逻辑，是连接结束后不再接收更新的直接原因。
- `home-agent:worker` 未运行时，任务本身也不会推进。

## `php think home-agent:worker` 如何领取并处理 `run_id`

### 1. `run_id` 的产生

调用 `POST /app.aigc_llm.agent/submit` 时，后端会在 `aigc_llm_agent_run` 表中创建任务记录。数据库记录的 `id` 就是返回给前端的 `run_id`，初始状态通常为 `queued`。

`run_id` 不是 Worker 主动生成的，也不是 SSE 连接生成的。SSE 只使用它订阅指定任务的状态。

### 2. Worker 的主循环

命令入口是：

```bash
php think home-agent:worker
```

入口文件：`app/common/command/HomeAgentWorker.php`。

Worker 启动后持续循环调用 `AgentExecutionService::tick()`。每次 `tick()` 先尝试领取一条可以执行的任务；没有任务时短暂休眠，然后继续轮询。

### 3. 领取条件

领取逻辑在 `AgentExecutionService::claimNextRun()`：

1. 查询 `status` 为 `queued` 或 `waiting_tasks` 的任务。
2. 要求 `next_run_time <= 当前时间`。
3. 按任务 `id` 顺序选择任务。
4. 使用数据库行锁（`lock(true)`）读取并更新，避免多个 Worker 同时领取同一个任务。
5. 领取成功后更新 `status = running`，写入随机 `lease_token`，并设置 `lease_until = 当前时间 + 1500 秒`。

所以 Worker 不是根据 SSE 请求来领取 `run_id`，而是从数据库中领取符合条件的任务记录。

### 4. 领取后的执行

领取任务后，Worker 根据任务当前 `phase` 执行对应步骤，包括 chat、长资料读取和工具步骤。执行过程中会根据结果更新任务状态：继续执行时回写 `waiting_tasks` 和 `next_run_time`，完成时进入成功终态，异常时进入失败终态并记录错误。

### 5. SSE 与 Worker 的关系

前端拿到 `run_id` 后调用 stream 接口订阅任务事件。stream 接口不会调用 LLM，也不会领取任务。

```text
submit -> 创建数据库任务并返回 run_id
worker -> 从数据库领取 run_id 并执行任务
stream -> 使用 run_id 读取任务事件并推送给前端
```

因此，Worker 没有运行时任务会停留在 `queued`；SSE 连接出现 `reconnect` 时只需要使用同一个 `run_id` 重新订阅。重新订阅不会重复领取任务，也不会重复调用 LLM。

### 6. 关键代码位置

- Worker 命令：`app/common/command/HomeAgentWorker.php`
- 任务轮询与执行：`app/common/service/app/aigc_llm/agent/AgentExecutionService.php` 的 `tick()`
- 领取入口：`AgentExecutionService.php` 的 `claimNextRun()`
- 任务加锁与租约：`claimNextRun()` 中的 `lock(true)`、`lease_token`、`lease_until`