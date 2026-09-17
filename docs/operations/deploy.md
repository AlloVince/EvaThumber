# 部署与交付
## 何时读
评估 Docker/Compose、镜像构建、健康状态或发布时。
## 已有部署事实
- Dockerfile 基于 `dunglas/frankenphp:1-php8.5-bookworm`；安装 libvips42/libvips-tools/libffi-dev/unzip 与 PHP FFI/pcntl，配置 ffi.enable=true、PHP memory_limit=256M。
- development/production 两阶段都 COPY composer.json + composer.lock；production 安装 no-dev，复制 src/public/bin/*.php，Caddyfile 放 `/etc/frankenphp/Caddyfile`，最终 www-data 运行。
- Caddy 固定 `:8081`，关闭自动 HTTPS/admin，16 个 FrankenPHP HTTP worker，所有路径 rewrite 到 index.php；access log JSON 输出 stdout。
- Compose 默认宿主 8081 → 容器 8081；data/images 只读挂载到 /data/images，命名卷缓存到 /data/cache；read_only 根文件系统，/tmp、/config/caddy、/data/caddy 为 tmpfs。
- Compose 设置 no-new-privileges、512m 内存、2 CPU、256 PID、45s stop_grace_period、unless-stopped；Caddy tmpfs 显式 uid/gid=33 与运行用户一致；临时目录也可写，不能称“仅缓存目录可写”。
- production 默认入口 `php /app/bin/serve.php`；启动本地 supervisor 与 FrankenPHP，变换池默认 2 个常驻进程。停机先 HTTP 后池，各等待最多 20s。队列与资源参数见 [config](config.md)。
## 已修复与实际验证
以下双架构/Compose 记录主要来自切池前版本，不作为当前 pool 版本完整验收；当前结果见 [progress](../progress.md)。
- FrankenPHP 与 Composer 使用已核验的多架构 manifest digest 固定；apt 源和 Dockerfile frontend 尚未固定，不宣称完全可复现构建。
- composer.lock、bin/transform.php 已取消 Git 忽略；需要随未来提交纳入，当前未执行 git add/commit。development 白名单包含 tests、bench、PHPUnit/PHPStan 配置。
- Compose healthcheck 已统一到 8081。
- 生产镜像移除 frankenphp 的 `cap_net_bind_service=ep`（只监听非特权端口 8081），Compose 丢弃全部 capabilities。原文件 capability 会使 `cap-drop=ALL` 下 exec 返回 Operation not permitted；两个架构均直接复现并修复验证。
- 本地 OrbStack arm64：arm64 原生、amd64 模拟执行，两个开发镜像完整测试均 34 tests / 360 assertions，PHPStan 通过；生产镜像均构建并真实 HTTP 验收通过。
- `tests/container-smoke.php` 在宿主机运行，接收镜像名、平台；创建随机名称/回环随机端口的独立容器，finally 清理；设 `EVATHUMBER_SMOKE_EVIDENCE=目录` 可保存本次容器完整日志。就绪探测单次最多 1s、总预算 20s，失败时输出内部 curl 与容器状态。测试 nonroot、只读根、cap-drop ALL、512MiB/2CPU/128PID、health、真实变换 MIME/20×15 尺寸、MISS/HIT、正文/ETag、304、HEAD、f_auto/Vary、analytics 和未知查询拒绝。
- 空闲 SIGTERM 停止：多次运行约 4.4–6.9s，exit 0、无 OOM；不是处理中的 graceful drain 证据。
- 历史 amd64 smoke 两次启动探测 20s 超时未复现；就绪探测改造后原生 arm64 连续 5 次及最新 amd64 均通过（就绪 9–146ms）。根因未定位，不能宣称已修复；早期通过记录不是稳定性保证。
- 完整 Compose Quick Start 已本地实跑（独立项目、隔离端口、只读原图绑定、命名缓存卷）：Healthy、真实变换、重启后命名卷 HIT 且字节/ETag 一致；满槽准入经 `tests/compose-admission.php` 真实 HTTP 验证。CI 远程执行、原生 amd64 负载与处理中停止仍待验收。
## 发布边界
CI image job 使用 buildx 双架构构建，v* tag 才推送 ghcr.io 仓库；当前无独立生产编排、TLS、备份或回滚脚本可引用。不可由此推定生产运维策略已建立。
## 相关
- 配置：`Dockerfile`、`compose.yaml`、`docker/Caddyfile`、`.dockerignore`、`.github/workflows/ci.yml`。
- [commands](../development/commands.md)、[testing](../development/testing.md)、[config](config.md)、[runtime](runtime.md)。
验证于：2026-09-17；本地双架构隔离容器验收，非远程 CI/GHCR 或生产部署验收。
