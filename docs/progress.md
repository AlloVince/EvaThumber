# 产品推进状态

## 最终目标

一个第一次看到 EvaThumber 的用户，只挂载自己的图片目录，就能可靠跑起官方 Docker 镜像并开始使用。

本文件只记录 RC1 验收项、已完成、未完成、实测结果与当前 blocker。历史基线与阶段流水账已移除；证据优先级为源码 > 测试 > 已确认决策 > 本文件。

## RC1 验收项

| # | 验收项 | 状态 | 证据 |
|---|---|---|---|
| 1 | production Docker image 可构建 | 通过 | `docker build --target production`（arm64 原生）；CI 双架构构建 |
| 2 | linux/amd64、linux/arm64 可运行 | 部分 | arm64 原生全绿；amd64 本地仅 QEMU 模拟，最终 gate 为原生 CI（第 14 项） |
| 3 | 只挂载 `/data/images` 即可使用 | 通过 | `tests/rc1-acceptance.php` A 段，仅用 README 两条命令 |
| 4 | `/data/cache` 不挂载完全正常 | 通过 | `tests/rc1-acceptance.php` A 段、`tests/product-acceptance.php` A 段 |
| 5 | 可选挂载 cache 跨容器重启复用 | 通过 | `tests/rc1-acceptance.php` B 段（重启后 HIT 且字节/ETag 一致）、`tests/docker-acceptance.php` 命名卷复用 |
| 6 | 声明支持的 transformation 正常 | 通过 | `tests/docker-acceptance.php` 29 项变换矩阵；每个 200 均解码并校验尺寸 |
| 7 | Cloudinary 风格 URL 端到端正常 | 通过 | `tests/docker-acceptance.php`、`tests/rc1-acceptance.php`（JPEG/PNG 源，WebP/AVIF 输出，`f_auto`、`q_auto`、`dpr_2`、crop、chain、304、HEAD、analytics） |
| 8 | 并发不产生错误图片、损坏 cache、死锁或不可恢复状态 | 通过 | 32 场压测零失败（每个 200 全量解码校验）；`tests/crash-recovery.php` 四类破坏场景 |
| 9 | overload 后服务可恢复 | 通过 | 压测高并发出现有界 503，后续场景立即恢复；健康探测全程 200 |
| 10 | graceful stop/restart 正常 | 通过 | `tests/crash-recovery.php` `graceful_stop_inflight`：在途两个请求均返回完整 200，exit 0、非 OOM，stop 306ms；重启 25ms 就绪 |
| 11 | 自动测试、静态分析、production smoke 全通过 | 通过 | arm64 原生容器内 uid 33：**63 tests / 877 assertions，0 skip**；PHPStan level 8（`src` + `bin`）通过；`tests/container-smoke.php` 通过 |
| 12 | 有真实 HTTP 压测报告 | 通过 | [`bench/results/rc1-http-full/`](../bench/results/rc1-http-full/)，32 场原始记录 |
| 13 | README Quick Start 可由首次接触者直接执行 | 通过 | 四套 Docker 验收脚本均只使用 README 公开的 `docker run` 参数 |
| 14 | 当前 commit 的 CI 与镜像构建证据完整 | **未完成** | workflow 已接入 `container-smoke`、`rc1-acceptance`、`crash-recovery`，但工作区未 commit、未推送，无远程运行结果 |

## 实测结果

### 自动化与静态分析

| 环境 | 结果 |
|---|---|
| arm64 Linux 容器（uid 33，与生产镜像同一用户） | 63 tests / 877 assertions，0 skip |
| arm64 Linux 容器（root） | 63 tests / 874 assertions，1 skip（权限位断言对 root 不成立） |
| macOS 宿主（PHP 8.5.11） | 63 tests / 861 assertions，1 skip（Linux 专属 PDEATHSIG 用例） |
| PHPStan level 8（`src`、`bin`，仅排除 `process-guard.php`） | 通过 |

### 并发压测

[`bench/results/rc1-http-full/`](../bench/results/rc1-http-full/)：production 镜像 arm64，512 MiB / 2 CPU / 默认 2 worker，2 轮 × 并发 2/4/8/16 × 4 场景，8s 窗口，**32 场零失败**。

| 场景 | 并发 | 成功 | 吞吐 (200/s) | p50 / p95 / p99 ms |
|---|---:|---|---:|---:|
| 热缓存 | 16 | 37,740 / 37,740 | ~4,700 | 3.3 / 5.7 / 7.1 |
| 同键冷启动 | 16 | 6,216 / 6,301 | ~775 | 14.8 / 62.9 / 75.3 |
| 不同键冷启动 | 4 | 204 / 204 | ~25 | 161 / 173 / 182 |
| 不同键冷启动 | 16 | 68 / 3,417，其余为有界 503 | ~8 | 804 / 915 / 1,613 |
| 混合 | 16 | 868 / 5,556，其余为有界 503 | ~107 | 2.8 / 612 / 1,134 |

- 同键冷启动在任意并发下**恰好 1 次变换**，并发等待者复用同一产物。
- 全部 503 均为 `cache_admission_full` / `processor_busy`（1 次 `queue_timeout`），即设计内的有界拒绝。
- 健康探测全程 200；8 个容器全部 exit 0、无 OOM；负载结束后下一场景立即恢复。
- 所有 200 响应经 MIME、尺寸与全量像素解码校验。
- 素材为本地 300px 照片及一张放大派生图，非真实原生大图；不含下载素材。
- `bench/results/rc1-http/` 是被 `rc1-http-full/` 取代的中断运行：驱动引用了已被重建掉的镜像 ID 而在第 5 个容器前中止（`No such image`，harness 失败，非服务失败）。保留仅为历史痕迹，不代表当前结果。
- `bench/results/` 下 `session-*`、`final-link`、`session-c1-diagnostic` 等目录是更早阶段的历史证据，不属于当前状态。

### 破坏与恢复

[`bench/results/rc1-crash-recovery/`](../bench/results/rc1-crash-recovery/)：`tests/crash-recovery.php` 连续两轮全通过。只用 README 的 `docker run -p 8080:8080 -v <dir>:/data/images:ro IMAGE`，不挂缓存卷、不加调优参数。每次 kill 都以 supervisor 日志（`job_started` 超过 `job_finished`）和 worker 私有 staging 文件双重证明落在编码中途。

| 场景 | 杀点 | 在途请求结果 | 容器结果 | 重启后 |
|---|---|---|---|---|
| 忙碌 worker SIGKILL | 正在编码的 `pool-worker.php` | 503 + 200（均合法解码） | 服务不降级，`/healthz` 200 | 24ms 就绪，产物与纯净容器逐字节一致 |
| 容器 SIGKILL | PID 1，信号处理器无法运行 | 两个请求均无响应 | 容器消失 | 30ms 就绪，产物逐字节一致，无残留 |
| 池 supervisor SIGKILL | `pool.php`，父进程猝死 | 两个请求均 503 | 容器自行退出，exit 1 | 34ms 就绪，产物逐字节一致，无残留 |
| 在途 graceful stop | 编码进行中 `docker stop` | 两个请求均完整 200 | exit 0、非 OOM，306ms | 25ms 就绪，缓存 HIT 保留 |

- 每次恢复后 `/tmp/evathumber/stage-*` 与 `/data/cache/.tmp-*` 残留均为 0。
- 每个恢复产物与"从未崩溃的纯净容器"逐字节一致；8 个参照产物互不相同，确保比对有区分度。
- 四场景之后容器仍能服务新的冷请求，最终 `docker stop` exit 0、无 OOM。

## 未完成

1. **第 14 项**：当前 commit 的远程 CI 与镜像构建证据。需要授权 commit 并推送。
2. **工作区未 commit**：端口 8080 对齐、`/readyz`、URL 版本段前置链修复、PHPStan 覆盖 `bin/`、四套 Docker 验收脚本、README 重写、本次破坏恢复套件与文档收敛均只在工作区。
3. **amd64 原生验证**：本地只有 QEMU 模拟证据；以原生 CI 为准。模拟环境下池 worker 出现间歇性 SIGKILL（服务按设计退避重启并继续服务，503 为有界拒绝），归因指向模拟层，arm64 原生零复现。
4. **硬 OOM 隔离**：现有的是 RSS 采样回收加有界拒绝，不是内核级内存硬上限。非 RC1 门槛，属 RC1 之后的迭代。

## 当前 blocker

只剩第 14 项，需要授权 commit 并推送以取得远程 CI 与双架构镜像构建证据。除此之外 RC1 清单已全部具备本地实测证据。

## 入口

[文档索引](index.md) · [发布评审](release-review.md) · [常驻池 ADR](architecture/adr/0002-persistent-transform-pool.md)
