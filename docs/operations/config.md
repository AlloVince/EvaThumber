# 运行配置
## 何时读
设置环境、调整资源或诊断启动配置错误时。
## 应用变量
前缀均为 `EVATHUMBER_`；来源为 Settings::fromEnvironment 与 Limits。
| 后缀 | 默认值 | 含义 |
|---|---|---|
| SOURCE | /data/images | 原图目录 |
| CACHE | /data/cache | 可写缓存目录 |
| MAX_SOURCE_BYTES | 33554432 | 源字节 |
| MAX_SOURCE_PIXELS | 40000000 | 源像素 |
| MAX_SOURCE_DIMENSION | 20000 | 源单边 |
| MAX_OUTPUT_DIMENSION | 4096 | 输出单边 |
| MAX_OUTPUT_PIXELS | 16000000 | 输出像素 |
| MAX_STEPS | 8 | 链步骤数 |
| MAX_PARAMETERS | 64 | 全链参数总数；旧 README 未列 |
| MAX_URL_LENGTH | 4096 | URL/变换串字节长度 |
| TIMEOUT | 15 | 单个处理任务截止秒数；池内超时强杀并回收 worker |
| CACHE_BYTES | 1073741824 | 缓存容量 |
| CACHE_ENTRIES | 10000 | 缓存条目上限 |
| MAX_AGE | 3600 | HTTP max-age 秒 |
| PHP_BINARY | PHP_BINARY 常量 | 子进程 PHP 可执行文件；Compose 设 /usr/local/bin/php |
| POOL_SOCKET | 空字符串；生产镜像 /tmp/evathumber/pool.sock | 私有 Unix socket；空值明确使用隔离 CLI，配置后池失败不降级 |
| POOL_SIZE | 2 | 常驻变换进程数，1..16 |
| POOL_QUEUE_SIZE | 4 | 等待任务上限，1..64；另受缓存 8 槽限制 |
| POOL_QUEUE_MS | 1000 | 排队截止毫秒，1..30000 |
| WORKER_MAX_JOBS | 500 | 每 worker 完成任务后回收阈值，尚待长期负载调优 |
| WORKER_RSS_MIB | 192 | Linux 采样 RSS 超限回收，至少 32；不是内核 OOM 隔离 |
池客户端总 IPC 预算为 TIMEOUT + POOL_QUEUE_MS/1000 + 3 秒；缓存 generation 等待采用相同预算，发布锁仍独立最多 250ms。配置大于 launcher drain 上限不能保证停机时任务完成。
整数环境值只接受 1..2147483647；非法值抛 InvalidArgumentException。特别是环境 MAX_AGE=0 被拒绝，虽直接 Settings 构造允许 maxAge=0。SOURCE/CACHE/PHP_BINARY 空值通过 `?:` 回默认。
## 部署级变量/固定值
- EVATHUMBER_PORT：Compose 宿主发布端口，默认 8080；不是 Caddy 容器监听端口。
- Docker ENV：VIPS_CONCURRENCY=2、VIPS_DISC_THRESHOLD=32m；Pipeline 还显式设置 concurrency=2。
- Docker SERVER_NAME=:8080，Caddyfile 站点同样写死 :8080，不以该变量模板化。
- 容器/PHP 内存是不同约束，PHP memory_limit 不能单独代表 FFI 原生内存上限。
## 相关
- 代码：`src/Http/Settings.php`、`src/Security/Limits.php`、`src/Image/Pipeline.php`。
- 部署：`Dockerfile`、`compose.yaml`、`docker/Caddyfile`；[deploy](deploy.md)。
验证于：2026-09-17。不记录任何密钥原文。
