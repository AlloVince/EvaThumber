# 部署与交付
## 何时读
评估 Docker/Compose、镜像构建、健康状态或发布时。
## 已有部署事实
- Dockerfile 基于 `dunglas/frankenphp:1-php8.5-bookworm`（多架构 digest 固定）；安装 libvips42/libvips-tools/libffi-dev/unzip 与 PHP FFI/pcntl，配置 ffi.enable=true、PHP memory_limit=256M。
- development/production 两阶段都 COPY composer.json + composer.lock；production 安装 no-dev，复制 src/public/bin/*.php，Caddyfile 放 `/etc/frankenphp/Caddyfile`，最终 www-data（uid 33）运行。
- Caddy 固定 `:8080`，关闭自动 HTTPS/admin，16 个 FrankenPHP HTTP worker，所有路径 rewrite 到 index.php；access log JSON 输出 stdout。
- Compose 默认宿主 8080 → 容器 8080；data/images 只读挂载到 /data/images，命名卷缓存到 /data/cache；read_only 根文件系统，/tmp、/config/caddy、/data/caddy 为 tmpfs。
- Compose 设置 no-new-privileges、512m 内存、2 CPU、256 PID、45s stop_grace_period、unless-stopped；Caddy tmpfs 显式 uid/gid=33 与运行用户一致。
- production 默认入口 `php /app/bin/serve.php`；启动本地 supervisor 与 FrankenPHP，变换池默认 2 个常驻进程。停机先 HTTP 后池，预算合计 8s（HTTP 5s、池取剩余），必须小于编排层宽限期。队列与资源参数见 [config](config.md)。
- `/healthz` 是纯存活响应，不查磁盘、codec 或变换池。`/readyz` 反映 source 可读、cache 可写与池至少一个存活 worker，不可用时 503；两者都不做实时图片编码。

## 挂载权限：唯一容易踩的坑
镜像内进程以 uid 33（`www-data`）运行。**挂载到 `/data/images` 的宿主目录必须能被 uid 33 读取**，否则容器内看不到任何原图。

- `mkdir -m 700 ~/photos` 在 macOS（OrbStack/Docker Desktop）上可用，因为它们把 bind mount 的属主重映射成容器用户；同一目录在 Linux 宿主上会以宿主 uid 与 `0700` 呈现，uid 33 无法进入，结果是全部图片请求 404。
- 正确做法：挂载目录及其父目录对其他可读（`chmod 755` 或组可读），这与普通图片目录的默认权限一致。
- 症状与定位：`/healthz` 仍为 200，但 `/readyz` 返回 503 且 `checks.source` 为 `false`。这是 readiness 设计的直接体现。
- 验收脚本已按 0755 创建 fixture 目录，避免依赖宿主 uid 恰好等于 33。

## 当前验证
完整证据与剩余门槛见 [progress](../progress.md)。要点：
- arm64 原生：容器内 uid 33 跑完整 suite **77 tests / 949 assertions，0 skip**；PHPStan level 8（`src` + `bin`）通过。
- 五套 Docker 验收全部通过，只用 README 公开的两条 `docker run` 参数：`container-smoke`、`rc1-acceptance`、`product-acceptance`、`docker-acceptance`、`crash-recovery`。
- `crash-recovery.php` 在编码中途分别强杀忙碌 worker、容器与池 supervisor，并加在途 graceful stop；每次恢复产物与纯净容器逐字节一致，staging/临时文件零残留。
- 32 场真实 HTTP 压测零失败，报告在 `bench/results/rc1-http-full/`。
- CI 在 ubuntu-24.04（amd64）与 ubuntu-24.04-arm（arm64）原生 runner 上执行上述测试与验收，run `36321362079` 全部 success。

## 构建可复现性
- 基础镜像与 Composer 使用已核验的多架构 manifest digest 固定。
- apt 源与 Dockerfile frontend 尚未固定，因此不宣称完全可复现构建。
- 实测同一份源码连续构建的 `RootFS.Layers` 逐层一致；镜像 config/manifest ID 会因 BuildKit attestation 元数据而变化，属预期，不是内容漂移。

## 发布边界
CI image job 使用 buildx 构建 linux/amd64、linux/arm64；分支 push 只构建缓存，`v*` tag 才用 `secrets.DOCKERHUB_TOKEN` 登录 Docker Hub 并推送 `docker.io/allovince/evathumber:2.0.0` 与 `:latest`。缓存是纯派生数据，删除即重建，不需要备份；TLS 终止与多副本编排由部署方负责，README 未承诺。当前无独立生产编排、回滚脚本或监控告警配置可引用。

## 相关
- [配置](config.md)、[运行排障](runtime.md)、[测试与 CI](../development/testing.md)、[进度](../progress.md)。
