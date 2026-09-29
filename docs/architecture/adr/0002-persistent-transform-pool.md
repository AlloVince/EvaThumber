# ADR 0002：本地有界常驻变换池（验收中）
## 上下文
用户要求生产 HTTP 冷缓存路径复用 libvips 初始化，并允许不同键真正并行。原先全局发布锁覆盖整个 producer，令多个 CLI 变换仍串行；仅增加 HTTP worker 数不能解除该限制。ADR 0001 的固定缓存准入保留，全局单生产者决策由本 ADR 替代。
## 决策与当前实现
- Docker 默认入口 `bin/serve.php` 同时启动 FrankenPHP 与 `bin/pool.php`；HTTP 通过私有 Unix socket 调用 `PoolProcessor`，不在每次 miss 创建 CLI。无 socket 配置的库/测试仍可明确使用 `IsolatedProcessor`；已配置池失败不自动退回隔离执行。
- supervisor 默认 2 个常驻 `pool-worker.php`，每个一次执行一个任务；默认 4 个等待位、1000ms 等待截止。worker、队列与截止可配置，范围见 [config](../../operations/config.md)。不完整请求帧单独限 16 个/200ms/16KiB；响应限 4KiB。IPC 仅可信本地使用，不是公开接口。
- 256 个稳定 generation lock stripes 代替全局变换锁；同键等待者复查结果，碰撞的不同键仍会串行。全局 `.publish.lock` 只覆盖临时文件登记/清理、容量处理与发布。缓存层固定 8 个 miss 槽位仍包括等待者，不与池队列合并。
- worker 只写私有 staging；成功后 supervisor 用 `r+b` 打开已存在的缓存临时文件，核对接收任务时的 dev/inode 后复制，不重建被删除路径、不覆盖替换 inode。HTTP 持有临时租约并负责最终原子发布。
- 处理截止使用单调时钟；超时 SIGKILL 并 reap 后补位。任务异常不发布；活动客户端断连会回收 worker；完成任务上限也触发回收。采样 RSS 超限**只在 worker 空闲时**回收：不回收就永不归还内存的常驻进程会一路漂到历史峰值，于是把本来能成功的任务误杀成 503（实测渐进式 17.9 MPix 原图一次 `w_600` 峰值 167 MiB、`q_auto` 178 MiB，默认上限 192 MiB）；空闲回收保留了"超限的 worker 不再接活"这一约束。与完成上限一样，空闲回收是计划内换手，不计入失败退避：放宽 `MAX_SOURCE_PIXELS` 后实测 36.4 MPix 渐进式源上 worker 每 2～5 个任务越过 192 MiB，按失败退避会把换手拖到 5s/槽并让等待位超时。默认 500 jobs/192MiB **尚非基于充分长期负载确定的最优值**。
- 停机先停止 HTTP，保留池供其 drain；之后停止池，拒绝待处理请求并等待活动任务。launcher 停机预算合计 8s（HTTP 5s、池取剩余），必须小于编排层宽限期（`docker stop` 默认 10s、Compose 45s），否则 PID 1 被 SIGKILL、容器 exit 137。直接 IPC 停机已测，生产 HTTP 活动/等待 drain 尚待验证。
## 选项与取舍
1. 保留每次 miss CLI：隔离简单，但违背目标，保留为显式基准模式而非失败降级。
2. 本地 supervisor + 有界常驻进程（采用）：保持单容器、无需依赖外部队列，代价是 IPC、生命周期与所有权必须独立验证。
3. 外部分布式队列/服务：当前未授权，运维复杂度不匹配本地图片服务。
## 已验证及限制
- `PoolQueueTest`：默认/非默认队列容量、排队截止、硬超时、reap 与后续恢复。
- `PoolLifecycleTest`：删除/替换目标、活动客户端断连、活动 SIGKILL 与人工部分 stage、直接 IPC 活动/待处理停机；均不等同于真实编码写入瞬间 SIGKILL 或 HTTP drain。
- `tests/pool-http.php`：生产 arm64 HTTP 迁移路径、same-key 成功结果一致且只启动一次变换、冷热 burst、损坏图、暂停 worker 超时和空闲 worker 强杀恢复。
- RSS 轮询不是内核 OOM 隔离；父进程猝死后的后代清理、启动失败退避、长时间压力和所有取消竞态仍需补证。dev/inode 校验不是对同 UID 恶意进程的安全边界。
- 高 burst 拒绝率尚未解决；缓存准入/条带等待/发布竞争与池饱和共享 `processor_busy`，不能只凭 HTTP 503 判定池已满。
- 来源摘要和前后身份复核尚不是不可变快照。性能、质量执行模型、双架构与 Compose 当前版本验收见 [progress](../../progress.md)。
## 相关
`bin/serve.php`、`bin/pool.php`、`bin/pool-worker.php`、`src/Image/PoolProcessor.php`、`src/Cache/DiskCache.php`。
验证于：2026-09-17；本决策已接入但未达到发布标准。
