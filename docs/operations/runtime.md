# 运行特征与排障
## 何时读
处理 HTTP 错误、缓存满、处理超时或 worker 健康问题时。
## 特征
HTTP 命中不启动处理子进程，但仍会解析 URL、查源 stat/MIME；全缓存目录同时只允许一个 miss 生产者，命中无锁。库直接调用不隔离、不缓存。
FrankenPHP worker 中 Settings/Kernel 持久，Request/Response 局部创建；入口最多 500 次调用，每次 gc_collect_cycles。环境配置变更需按部署方式重启进程。
`/healthz` 为静态存活响应，不检查磁盘、PHP CLI 或 codecs。GET/HEAD 限制在 healthz 之前，查询串拒绝在其之后。
## 排障入口
| 现象 | 代码含义/优先核查 |
|---|---|
| 400 invalid/unsupported_transformation | Parser/ParameterRules；q_auto、g_auto 和受限组合拒绝 |
| 404 not_found/source_unavailable | 路由或原图不存在；查 Url 与 LocalSource |
| 409 source_unavailable | 一个 public ID 对应多个原图；不要任意挑一个 |
| 405 method_not_allowed | 仅 GET/HEAD，Allow 响应头 |
| 406 not_acceptable | Accept 没有可用正质量候选 |
| 413 image_too_large / 414 url_too_long | Limits 与解析长度；检查中间输出尺寸，不只最终尺寸 |
| 415 unsupported_format/unsupported_animation | MIME/扩展名、显式 loader、多页源限制 |
| 422 invalid_image | 解码/编码失败，或子进程失败且未返回结构化领域错误；核查 CLI/codec/脚本存在性 |
| 503 processor_busy/cache_unavailable | 非阻塞准入繁忙或缓存不可用；响应 Retry-After: 1；不要无限重试 |
| 504 processing_timeout | Process 达截止时间；检查原图、负载与 TIMEOUT |
| 507 cache_full | 缓存条目/字节上限，或新图超过剩余容量；没有自动淘汰 |
| 500 internal_error / 启动失败 | Kernel 未知异常写 PHP 日志；Settings 初始化在请求外，需查进程启动日志 |
| Compose unhealthy | 先核对 8080/8081 健康检查差异，见 deploy |
## 缓存与日志约束
缓存生成旧键不会随 max-age 或源变化自动删除；没有已验证的在线清理/备份流程。维护者需确定停写协调、保留策略再执行，不能直接删正在使用的锁文件。
Caddy access log → stdout；Docker PHP error_log → stderr。子进程 stderr 未由 IsolatedProcessor 显式转发，不能保证能从服务日志看到完整子进程异常。客户端不返回本地路径；共享日志前脱敏。
## 相关
- 代码：`public/index.php`、`src/Http/Kernel.php`、`src/Cache/DiskCache.php`、`src/Image/IsolatedProcessor.php`、`bin/transform.php`。
- [配置](config.md)、[部署](deploy.md)、[测试缺口](../development/testing.md)。
验证于：2026-09-17；错误分支主要由代码确认，未逐项运行故障实验。
