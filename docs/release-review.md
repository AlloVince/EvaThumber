# EvaThumber 发布评审

## 结论

**Go。**

2.0.0 清单 14 项全部具备实测证据，包括原生 amd64 与 arm64 两个 runner 上的完整测试、PHPStan、五套 Docker 验收，以及 buildx 双架构镜像构建。

本报告取代 2026-09-21 的前次评审（结论 No-Go）。前次列出的 P0/P1 阻断项已逐条关闭，证据见下方与 [进度](progress.md)；未关闭项集中列在第 4 节。

## 结论：Go，直接发 2.0.0

不发布 RC。2.0.0 走正式发布：git tag `v2.0.0` → CI 推送 `docker.io/allovince/evathumber:2.0.0` 与 `:latest`。发布前置条件只有一项：仓库 secret `DOCKERHUB_TOKEN`（Docker Hub access token，账号 `allovince`）已配置。

## 评审范围

| 项目 | 当前事实 |
|---|---|
| 项目 | EvaThumber 2，自托管图片变换服务与 Composer 库 |
| 分支 | `main`（原 `master` + `feat/v2-cloudinary-compat` 已合并） |
| 运行栈 | PHP 8.5、libvips 8.14.1、FrankenPHP |
| 生产入口 | `php /app/bin/serve.php`，同时管理 HTTP 与本地变换池 |
| HTTP 端口 | 容器 `8080`（README Quick Start 唯一暴露的端口） |
| 数据源 | 本地目录只读挂载 `/data/images`；派生缓存 `/data/cache` |
| 不支持 | 远程源、鉴权、多租户、TLS、管理后台、动画、Cloudinary 全量语义 |

## 已关闭的前次阻断项

| 前次阻断项 | 现状 | 证据 |
|---|---|---|
| 常驻池真实 HTTP drain 未验证 | 已关闭 | `tests/crash-recovery.php` 四场景：忙碌 worker SIGKILL、容器 SIGKILL、池 supervisor SIGKILL、在途 graceful stop。每次 kill 由 supervisor 日志与 worker staging 文件双重证明落在编码中途；恢复产物与纯净容器逐字节一致，staging/临时文件零残留 |
| 编码写缓存瞬间强杀未验证 | 已关闭 | 同上；发布路径为"staging → 核对目标 dev/ino → 写回 → 硬链接发布"，崩溃只能丢弃工作，不能发布半成品 |
| 父进程猝死清理未验证 | 已关闭 | Linux `PR_SET_PDEATHSIG` 用例在原生容器内执行（`PoolRecoveryTest`，非 skip）；`crash-recovery.php` 另验证杀掉 `pool.php` 后容器自行退出并可重启恢复 |
| 持续负载出现 unclean shutdown | 已关闭 | 32 场压测零失败，8 个容器全部 exit 0、无 OOM |
| 503 无法逐请求归因 | 已关闭 | 全部 503 均落在 `cache_admission_full` / `processor_busy`（1 次 `queue_timeout`），即设计内的有界拒绝 |
| amd64 远程原生 CI 缺失 | 已关闭 | 原生 runner 分流（amd64→ubuntu-24.04，arm64→ubuntu-24.04-arm）已实际运行通过，run 36321362079，证据归档在 `bench/results/rc1-ci/` |
| q_auto 无视觉验收 | 降级为已知边界 | `q_auto` 是本地边缘密度启发式，不声称等同 Cloudinary。README 与文档已明确标注；算法质量不作为 2.0.0 门槛 |
| 文档测试数字不一致 | 已关闭 | 全部当前状态文档统一为 63 tests / 877 assertions（arm64 容器 uid 33）；历史数字只留在按 commit 记录的 `branch-review-315303a.md` |
| 健康检查不代表池可用 | 已关闭 | 新增 `/readyz`：校验 source 可读、cache 可写、池至少一个存活 worker；不可用时 503 而 `/healthz` 仍 200。不做实时图片编码 |
| 缓存身份未含 codec 版本 | 接受为已知语义 | 缓存身份含策略串 `evathumber-2-policy-3` 与 q_auto policy；底层 codec 升级后需显式提升策略串。发布说明中已列为运维注意事项 |
| 运维闭环（TLS/备份/回滚 runbook） | 不在 2.0.0 范围 | 缓存是纯派生数据，删除即重建，不需要备份。TLS 终止与多副本编排属于部署方职责，README 未承诺 |

## 当前验证

| 验证 | 结果 |
|---|---|
| PHPUnit，原生 CI amd64 / arm64 容器 uid 33 | 两架构均 63 tests / 877 assertions，0 skip |
| PHPUnit，macOS 宿主 | 63 / 861，1 skip（Linux 专属 PDEATHSIG） |
| PHPStan level 8（`src` + `bin`） | 通过 |
| `tests/container-smoke.php` | 通过（两个原生 runner） |
| `tests/rc1-acceptance.php` | 通过（A 无缓存卷 / B 持久卷重启 HIT + 清空安全） |
| `tests/product-acceptance.php` | 通过（A / B / C 只读缓存 → `/readyz` 503） |
| `tests/docker-acceptance.php` | 通过（141 项检查，29 项变换矩阵） |
| `tests/crash-recovery.php` | 通过（本地三轮 + 两个原生 runner，四场景） |
| 真实 HTTP 压测 | 32 场零失败，见 `bench/results/rc1-http-full/` |
| `docker compose config --quiet` | 通过 |
| 远程原生 CI（amd64 + arm64） | 通过，主干最近一次全绿 run 36322300814（commit `aade6db`） |
| buildx 双架构镜像构建 | 通过（同一次 run 的 `image` job）；分支 push 不推镜像 |

## 未关闭项

均不阻塞 2.0.0，属发布后的迭代：

1. **硬 OOM 隔离**。现有的是 RSS 采样回收加有界拒绝，不是内核级内存硬上限。
2. **q_auto 真实语料视觉校准**。已知边界，非 2.0.0 门槛。
3. **停机时长上界**。Caddy 的 graceful shutdown 无上界，约 4% 的 `docker stop` 会被残留连接拖满 20s；此时强杀 FrankenPHP 但仍 exit 0，只有池子被强杀才 exit 1。
4. **来源在 pool-worker 解码期间的随机并发替换**。已有确定性跨进程反例与前后身份复核；随机压力注入未做。
5. **镜像发布**。发布链路已改为 Docker Hub（`docker.io/allovince/evathumber`），随 `v2.0.0` tag 推送；tag 推送后的实测记录见 [进度](progress.md) 的「发布」节。

## 评审签字栏

| 角色 | 结论 | 日期 |
|---|---|---|
| 代码评审 | 通过 | 2026-09-27 |
| 测试与可靠性 | 通过（amd64/arm64 原生 CI 均绿） | 2026-09-27 |
| 运维与安全 | 通过（缓存免备份；TLS 由部署方负责） | 2026-09-27 |
| 发布批准 | **Go**，直接发 2.0.0（已授权） | 2026-09-27 |

## 证据入口

- [进度与 2.0.0 清单](progress.md)
- [架构概览](architecture/overview.md)
- [常驻池 ADR](architecture/adr/0002-persistent-transform-pool.md)
- [测试与 CI](development/testing.md)
- [部署交付](operations/deploy.md)
