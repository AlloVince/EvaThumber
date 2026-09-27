# 运行特征与排障
## 何时读
处理 HTTP 错误、缓存满、处理超时或 worker 健康问题时。
## 特征
HTTP 命中不调用变换池，但仍解析 URL、查源 stat/MIME 并读取完整摘要；miss 经 8 个准入槽、256 个 generation stripes 后进入常驻池。同键等待者复用发布结果但也占槽；不同条带可以并行。generation 等待受配置预算限制，发布锁每次最多 250ms。命中持有条目共享租约；孤儿临时文件由后续 producer 在取得临时文件独占租约后清理。库直接调用不隔离、不缓存。
FrankenPHP worker 中 Settings/Kernel 持久，Request/Response 局部创建；入口最多 500 次调用，每次 gc_collect_cycles。环境配置变更需按部署方式重启进程。
`/healthz` 为静态存活响应，不检查磁盘、PHP CLI 或 codecs。GET/HEAD 限制在 healthz 之前；图片 URI 长度限制与查询参数白名单检查在其之后，只接受标量 `_a`/`_i`。`/readyz` 反映 source 可读、cache 可写与池至少一个存活 worker，不可用时 503，同样不做实时编码。

## 停机行为
`docker stop` → SIGTERM 送到 PID 1（`bin/serve.php`）→ 先 SIGTERM FrankenPHP 让在途请求跑完，再 SIGTERM 变换池，每个子进程最多等 20s（必须大于 `EVATHUMBER_TIMEOUT` 加队列截止，否则会丢掉在途工作）。Compose 的 `stop_grace_period` 为 45s。

- 正常情况：约 0.3s 内退出，exit 0。在途请求会拿到完整的 200。
- Caddy 的 graceful shutdown 没有上界（本版本 Caddyfile 适配器未暴露 `shutdown_delay`），偶发（约 4%）会被一条残留连接拖满 20s。`serve.php` 此时 SIGKILL FrankenPHP 并输出 `{"event":"shutdown_forced"}`。这仍算正常停机，容器 exit 0：HTTP 层不持有任何状态。只有**池**子进程被强杀才 exit 1，因为那意味着在途 staging 可能丢失。
- 若需要严格的上界停机时间，在编排层把 terminationGracePeriod 设大于 45s。
## 排障入口
| 现象 | 代码含义/优先核查 |
|---|---|
| 400 invalid/unsupported_transformation | Parser/ParameterRules；g_auto、未知/sensitive 质量档位、PNG/GIF 的 q_auto 及受限组合拒绝 |
| 404 not_found/source_unavailable | 路由或原图不存在；查 Url 与 LocalSource |
| 409 source_unavailable | 一个 public ID 对应多个原图；不要任意挑一个 |
| 405 method_not_allowed | 仅 GET/HEAD，Allow 响应头 |
| 406 not_acceptable | Accept 没有可用正质量候选 |
| 413 image_too_large / 414 url_too_long | Limits 与解析长度；检查中间输出尺寸，不只最终尺寸 |
| 415 unsupported_format/unsupported_animation | MIME/扩展名、显式 loader、多页源限制 |
| 422 invalid_image | 解码/编码失败，或子进程失败且未返回结构化领域错误；核查 CLI/codec/脚本存在性 |
| 503 processor_busy/cache_unavailable/cache_busy | 缓存准入已满、generation/发布锁截止、池饱和、目标临时文件已失去所有权或容量暂不可回收；响应 Retry-After: 1。该错误不能独自定位阶段 |
| 503 queue_timeout/pool_stopping/processor_unavailable | 池排队截止、停机或 IPC 失败；有界重试，勿无限循环 |
| 504 processing_timeout | 池任务达截止后强杀/reap，或隔离 Process 超时；检查原图、负载与 TIMEOUT |
| 507 cache_full | 新产物为空或超过整个字节容量；容量不足会先尝试淘汰未租用条目 |
| 500 internal_error / 启动失败 | Kernel 未知异常写 PHP 日志；Settings 初始化在请求外，需查进程启动日志 |
| Compose unhealthy | 健康端口已统一 8080；检查启动日志、挂载目录权限与 CLI，见 deploy |
## 池事件与限制
supervisor stderr 输出 worker_start/ready/stop、job_started/finished、pool_response 与单调时间；pool_response 带 active/capacity/queued。sequence 是响应序号，不是贯穿 HTTP 的请求 ID；不能据数量差逐请求归因。worker RSS 采样超限会回收，但不能保证内核 OOM 只杀 worker。healthz 只表示进程存活，不检查池 readiness；池与磁盘状态看 /readyz。
## 缓存与日志约束
缓存生成旧键不会随 max-age 或源变化自动删除；没有已验证的在线清理/备份流程。维护者需确定停写协调、保留策略再执行，不能直接删正在使用的锁文件。
Caddy access log → stdout；Docker PHP error_log → stderr。子进程 stderr 未由 IsolatedProcessor 显式转发，不能保证能从服务日志看到完整子进程异常。客户端不返回本地路径；共享日志前脱敏。
## 相关
- 代码：`public/index.php`、`src/Http/Kernel.php`、`src/Cache/DiskCache.php`、`src/Image/IsolatedProcessor.php`、`bin/transform.php`。
- [配置](config.md)、[部署](deploy.md)、[测试缺口](../development/testing.md)。
验证于：2026-09-17；错误分支主要由代码确认，未逐项运行故障实验。
