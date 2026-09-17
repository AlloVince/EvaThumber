# 部署与交付
## 何时读
评估 Docker/Compose、镜像构建、健康状态或发布时。
## 已有部署事实
- Dockerfile 基于 `dunglas/frankenphp:1-php8.5-bookworm`；安装 libvips42/libvips-tools/libffi-dev/unzip 与 PHP FFI，配置 ffi.enable=true、PHP memory_limit=256M。
- development/production 两阶段都 COPY composer.json + composer.lock；production 安装 no-dev，复制 src/public/bin/transform.php，Caddyfile 放 `/etc/frankenphp/Caddyfile`，最终 www-data 运行。
- Caddy 固定 `:8081`，关闭自动 HTTPS/admin，两个 FrankenPHP worker，所有路径 rewrite 到 index.php；access log JSON 输出 stdout。
- Compose 默认宿主 8081 → 容器 8081；data/images 只读挂载到 /data/images，命名卷缓存到 /data/cache；read_only 根文件系统，/tmp、/config/caddy、/data/caddy 为 tmpfs。
- Compose 设置 no-new-privileges、512m 内存、2 CPU、128 PID、unless-stopped；临时目录也可写，不能称“仅缓存目录可写”。
## 已知交付问题（未修复）
1. composer.lock 与 bin/transform.php 在本地存在但被 Git 忽略，干净检出缺少 Docker COPY/HTTP 子进程所需输入。
2. Compose healthcheck 请求 localhost:8080；Caddy、Dockerfile healthcheck 和发布端口均为 8081。不保证启动后 healthy。
3. `.dockerignore` 为默认全排除加白名单；development 阶段 COPY . 不保证含测试和工具配置，不能当完整开发镜像使用。
4. 未执行 Docker build/up 或真实多架构验收；README 的“生产级”不是本次结论。CI 已知问题见 testing。
## 发布边界
CI image job 使用 buildx 双架构构建，v* tag 才推送 ghcr.io 仓库；当前无独立生产编排、TLS、备份或回滚脚本可引用。不可由此推定生产运维策略已建立。
## 相关
- 配置：`Dockerfile`、`compose.yaml`、`docker/Caddyfile`、`.dockerignore`、`.github/workflows/ci.yml`。
- [commands](../development/commands.md)、[testing](../development/testing.md)、[config](config.md)、[runtime](runtime.md)。
验证于：2026-09-17，静态配置核对，非部署验收。
