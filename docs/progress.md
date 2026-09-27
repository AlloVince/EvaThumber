# 产品推进状态

## 最终目标

一个第一次看到 EvaThumber 的用户，只挂载自己的图片目录，就能可靠跑起官方 Docker 镜像并开始使用。

本文件只记录 2.0.0 验收项、已完成、未完成、实测结果与当前 blocker。历史基线与阶段流水账已移除；证据优先级为源码 > 测试 > 已确认决策 > 本文件。

## 2.0.0 验收项

| # | 验收项 | 状态 | 证据 |
|---|---|---|---|
| 1 | production Docker image 可构建 | 通过 | `docker build --target production`（arm64 原生）；CI 双架构构建 |
| 2 | linux/amd64、linux/arm64 可运行 | 通过 | 原生 CI：amd64 在 ubuntu-24.04、arm64 在 ubuntu-24.04-arm，两架构全部测试与五套验收通过（[run 36321362079](../bench/results/rc1-ci/README.md)） |
| 3 | 只挂载 `/data/images` 即可使用 | 通过 | `tests/rc1-acceptance.php` A 段，仅用 README 两条命令 |
| 4 | `/data/cache` 不挂载完全正常 | 通过 | `tests/rc1-acceptance.php` A 段、`tests/product-acceptance.php` A 段 |
| 5 | 可选挂载 cache 跨容器重启复用 | 通过 | `tests/rc1-acceptance.php` B 段（重启后 HIT 且字节/ETag 一致）、`tests/docker-acceptance.php` 命名卷复用 |
| 6 | 声明支持的 transformation 正常 | 通过 | `tests/docker-acceptance.php` 29 项变换矩阵；每个 200 均解码并校验尺寸 |
| 7 | Cloudinary 风格 URL 端到端正常 | 通过 | `tests/docker-acceptance.php`、`tests/rc1-acceptance.php`（JPEG/PNG 源，WebP/AVIF 输出，`f_auto`、`q_auto`、`dpr_2`、crop、chain、304、HEAD、analytics） |
| 8 | 并发不产生错误图片、损坏 cache、死锁或不可恢复状态 | 通过 | 32 场压测零失败（每个 200 全量解码校验）；`tests/crash-recovery.php` 四类破坏场景 |
| 9 | overload 后服务可恢复 | 通过 | 压测高并发出现有界 503，后续场景立即恢复；健康探测全程 200 |
| 10 | graceful stop/restart 正常 | 通过 | `tests/crash-recovery.php` `graceful_stop_inflight`：在途两个请求均返回完整 200，exit 0、非 OOM，stop 306ms；重启 25ms 就绪 |
| 11 | 自动测试、静态分析、production smoke 全通过 | 通过 | 两个原生 runner 容器内 uid 33：**63 tests / 877 assertions，0 skip**；PHPStan level 8（`src` + `bin`）通过；`tests/container-smoke.php` 通过 |
| 12 | 有真实 HTTP 压测报告 | 通过 | [`bench/results/rc1-http-full/`](../bench/results/rc1-http-full/)，32 场原始记录 |
| 13 | README Quick Start 可由首次接触者直接执行 | 通过 | 四套 Docker 验收脚本均只使用 README 公开的 `docker run` 参数 |
| 14 | 当前 commit 的 CI 与镜像构建证据完整 | 通过 | 主干最近一次全绿：run `36322300814`（commit `aade6db`），`test` amd64/arm64 与 `image` 双架构构建全部 success；tag 发布记录见「发布」节 |

## 实测结果

### 自动化与静态分析

| 环境 | 结果 |
|---|---|
| 原生 CI amd64 / arm64 容器（uid 33，与生产镜像同一用户） | 两架构均 63 tests / 877 assertions，0 skip |
| 本机 arm64 Linux 容器（uid 33） | 63 tests / 877 assertions，0 skip |
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
- 原生 CI 证据：[`bench/results/rc1-ci/`](../bench/results/rc1-ci/)。

### 破坏与恢复

[`bench/results/rc1-crash-recovery/`](../bench/results/rc1-crash-recovery/)：`tests/crash-recovery.php` 连续三轮全通过，并在两个原生 CI runner 上各通过一次。只用 README 的 `docker run -p 8080:8080 -v <dir>:/data/images:ro IMAGE`，不挂缓存卷、不加调优参数。每次 kill 都以 supervisor 日志（`job_started` 超过 `job_finished`）和 worker 私有 staging 文件双重证明落在编码中途。

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

以下均不属于 2.0.0 门槛，属发布后的迭代：

1. **硬 OOM 隔离**：现有的是 RSS 采样回收加有界拒绝，不是内核级内存硬上限。
2. **q_auto 真实语料视觉校准**：本地边缘密度启发式，真实摄影/文字/透明素材的视觉验收未做。README 与兼容矩阵已标注不等同 Cloudinary。
3. **停机时长上界**：Caddy 的 graceful shutdown 无上界，会被一条残留连接无限拖住。`serve.php` 把整个停机预算收敛为 8s（HTTP 5s、池取剩余），到点强杀并记 `shutdown_forced` 仍 exit 0；只有池子被强杀才 exit 1。预算必须小于编排层宽限期，否则 PID 1 被 SIGKILL、容器 exit 137。该收敛只在 2.0.0 之后的 main 上，已发布的 `2.0.0`/`:latest` 仍是旧的 20s 预算。
4. **来源在 pool-worker 解码期间的随机并发替换**：已有确定性跨进程反例与前后身份复核，随机压力注入未做。
5. **amd64 本地模拟下的池 worker 间歇死亡**：仅 QEMU 模拟出现，服务按设计退避重启；原生两架构零复现。

## 环境事实

- 挂载到 `/data/images` 的宿主目录必须能被容器内 uid 33 读取。`0700` 目录在 macOS 上可用、在 Linux 上会让全部图片请求 404；症状是 `/readyz` 报 `"source": false`。
- 验收脚本一律以 0755 创建 fixture 目录，fixture 生成以 root 在一次性容器内完成。OrbStack 会把 bind mount 属主重映射成容器用户，从而掩盖所有权限问题——只有原生 CI 能暴露。
- 同一份源码连续构建的 `RootFS.Layers` 逐层一致；镜像 manifest ID 会因 BuildKit attestation 元数据变化，属预期。

## 当前状态

2.0.0 清单 14 项全部具备实测证据。发布链路：给仓库加 secret `DOCKERHUB_TOKEN`（Docker Hub access token，账号 `allovince`），再推 `v2.0.0` tag，CI 即推送 `docker.io/allovince/evathumber:2.0.0` 与 `:latest`。缺 secret 时发布步会直接失败而不产出半发布镜像。

## 发布

- 目标仓库：`docker.io/allovince/evathumber`（沿用 2018 年 v1 已存在的公开仓库；旧的 `latest`/`1.0.0`/`1.0.1` 标签中 `latest` 被 2.0.0 覆盖，`1.0.0`/`1.0.1` 保留）。
- git tag 用 `v2.0.0`（CI 触发条件是 `v*`），镜像标签去掉 `v` 以延续 v1 的历史命名习惯。

### 已发布：2.0.0

- tag `v2.0.0` → commit `f383e21`。
- CI run [`36328625145`](https://github.com/AlloVince/EvaThumber/actions/runs/36328625145)：`test`（amd64、arm64 原生）与 `image` 三个 job 全部 success；`image` 登录 Docker Hub 后由 buildx 推送双架构。
- 多架构 index digest `sha256:e87bbdab3c0d23c70ebad214a9d9a0ea9046879bfe815f098ec431fa7f238c5f`，`:2.0.0` 与 `:latest` 指向同一 index；amd64 manifest `sha256:fdc4f5fb…`、arm64 manifest `sha256:ed5ba789…`。
- 匿名可拉取：`auth.docker.io` 匿名 token 请求两个标签的 manifest 均返回 200，无需登录。
- 本机（arm64 macOS + OrbStack）用 README 原样两参数命令实测已发布镜像：`/healthz` 报 `2.0.0`、`/readyz` ready、`c_fill,w_300,h_300/f_webp` 冷请求 200（300×300，52ms）→ 第二次 `X-Evathumber-Cache: HIT`（2.4ms，带 ETag）、`f_avif` 200、容器内为 uid 33。
- 对已发布镜像重跑 `tests/rc1-acceptance.php` 与 `tests/docker-acceptance.php`（141 项）均 exit 0。权限位相关的结论仍以原生两架构 CI 为准（OrbStack 会重映射 bind mount 属主）。

## 入口

[文档索引](index.md) · [发布评审](release-review.md) · [常驻池 ADR](architecture/adr/0002-persistent-transform-pool.md)
