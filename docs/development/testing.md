# 测试与 CI
## 何时读
改行为、加回归、判断验证证据或排查 CI 时。
## 运行与惯例
PHPUnit 12，vendor/autoload.php 引导；tests/v2 为唯一 suite，warning/risky 视为失败。数据提供器用 `#[DataProvider]` 属性，不使用旧 doc-comment 注解。
测试用 libvips 实时生成小图片、随机临时目录，并在 finally/tearDown 清理；不要引入生产原图。macOS 路径断言使用 realpath。无 alpha 的三波段图不能假定可直接 flatten。

libvips 测试中的同一 PHP 进程重复处理可能受进程内状态影响；定位这类差异时用 fresh process 隔离请求。libvips 也可能按 inode 缓存已 mmap 的文件：不要原地覆写后复用同一路径验证图像内容，改用新文件名或 `Image::newFromBuffer()`。
## 已有覆盖
| 文件 | 实际覆盖 |
|---|---|
| `tests/v2/PipelineTest.php` | f 覆盖、投递格式默认值、4 组不支持参数、fill 像素/尺寸、fit 后旋转；末尾 q/f 分段等价、直接模型构建、重复键与后续像素操作拒绝；1200×800 原图上 a_90/a_180/a_270/a_-90/a_hflip/a_vflip 六个方向；ar 尺寸 7 例（含 600×16:9 的 .5 像素边界，按 canonical 串回读渲染） |
| `tests/v2/StorageAndUrlTest.php` | cloud/version/chain/嵌套及歧义边界 URL、来源 MIME 与歧义、缓存命中绕过 producer、容量失败原子性与临时清理；ar canonical 回读与无指数记法断言 |
| `tests/v2/CacheLifecycleTest.php` | 最老写入淘汰、生产失败保留旧条目、跳过被租用条目 |
| `tests/v2/CacheConcurrencyTest.php` | 跨 PHP 进程同键 miss 复用发布产物、忙等 250ms 截止后 503、命中绕过忙锁、强杀 producer 后锁释放与孤儿临时清理；7 占用槽位加真实 producer 填满 8 槽、溢出立即 503、命中绕过满槽、SIGKILL 后全部槽位恢复 |
| `tests/v2/HttpTest.php` | 直接 Kernel 调用：真实子进程变换、f_auto WebP、Vary、MISS/HIT、ETag 304、非法变换、healthz；发送租约与回收、版本隔离、analytics 白名单与 URI 限长、投递分段共享缓存、忙准入 503 Retry-After 后恢复 |
| `tests/v2/AutoQualityTest.php` | 内容/格式自适应 Q、四档顺序、极小/透明图、三格式四档编码与显式 Q 字节一致、PNG/GIF 拒绝、HTTP 缓存身份 |
新增 `tests/v2/ProcessorFailureTest.php`：真实 Symfony Process 的超时 504、异常退出 422、结构化拒绝 413，均验证部分文件不发布、临时清理及下一次真实变换恢复。`HttpTest` 另验证编码空格/加号在单条目淘汰后的身份与 ETag。
当前完整 suite（`vendor/bin/phpunit`）：**77 tests / 949 assertions，0 skip**（Linux 容器内以 uid 33 运行，与生产镜像同一用户；原生 CI 的 amd64 与 arm64 runner 结果一致）。同一 suite 以 root 运行时为 77 / 946、1 skip（权限位断言对 root 不成立）。macOS 宿主的数字本次未重测（宿主 PHP 无 libvips，只有容器内可跑），需要时在装了 libvips 的 macOS 上重跑再回填。详细证据见 [进度](../progress.md)。

`progress.md` 与 `release-review.md` 里的 63 / 877 是 2.0.0 发布时按 commit 与 CI run 冻结的发布证据，不要改写；它们与本页数字不一致是预期的。

**fixture 尺寸必须覆盖真实体量。** 2.0.0 的 `a_90` 只在 300×200 这类 fixture 上验证过，而 `vips_rot`/`vips_flip` 配 `access=sequential` 的源要超过约 1 MPix 才失败，于是整套测试与 `tests/docker-acceptance.php` 的 300×200 矩阵一起漏掉了「任何相机尺寸原图直接旋转都返回 422」。回归用例因此固定用 1200×800。新增涉及 libvips 操作、编解码或内存的用例时，先确认阈值落在 fixture 尺寸的另一侧。

- `PoolQueueTest`：真实 supervisor，默认/非默认队列容量与截止、暂停 worker 硬超时、reap、恢复。
- `PoolLifecycleTest`：删除/替换临时目标不被写回；断连回收；活动 SIGKILL 与人工部分 stage；直接 IPC 停机拒绝待处理请求、完成活动任务。人工 stage 不等于真实编码写入时刻强杀；不等于生产 HTTP drain。
- `SourceConsistencyTest`：atime 不影响身份、producer 回调内同步替换导致 409 且不发布。尚未验证真实 worker 解码期间的并发替换。
- `tests/pool-http.php [image] [pool|isolated]`：真实 arm64 容器 HTTP 迁移 URL、冷热/同键/混合 40 请求 burst、资源采样、超时恢复和空闲 SIGKILL/停止。设置 EVATHUMBER_EVIDENCE 保存报告与日志。不是持续固定并发 benchmark；当前接受任意 503，报告通过不意味着拒绝来源正确。
- `tests/resource-sample.php`：Linux cgroup CPU、总进程/worker RSS 的 10ms 采样；包含观察器开销、RSS 共享页重复计数，不是精确峰值。
只读源码挂载运行 PHPUnit 时使用 `--do-not-cache-result`，避免结果缓存写入警告。
## 静态分析与缺口
`composer analyse`：**通过**（level 8，覆盖 `src` 与 `bin`；仅排除 `bin/process-guard.php`，其 `prctl()`/`getppid()` 经 `FFI::cdef` 运行时声明，PHPStan 无法解析签名）。未加忽略规则或降低等级。

## Docker 验收脚本
五套脚本都只使用 README 公开的 `docker run -p 8080:8080 -v <dir>:/data/images:ro IMAGE`（需要宿主机 Composer 依赖与 Docker，不在应用容器内运行）：

脚本本身**不依赖宿主 PHP 具备 libvips**：所有解码与 fixture 生成都通过 `tests/image-oracle.php` 在被测容器内完成，因此可以直接在只有普通 PHP 的 CI runner 上运行。fixture 目录一律以 0755 创建，因为容器内是 uid 33 而目录属主是运行脚本的人，`0700` 在 Linux 上会让容器读不到原图（OrbStack 会重映射挂载属主从而掩盖这一点）。

挂载权限是真实用户也会踩的坑：图片目录必须对 uid 33 可读，否则 `/readyz` 报 `"source": false`、图片请求全部 404。

| 脚本 | 覆盖 |
|---|---|
| `tests/container-smoke.php` | nonroot、只读根、cap-drop ALL、真实网络变换、MIME/尺寸、MISS/HIT/HEAD/304、`f_auto`/Vary、analytics、空闲 SIGTERM exit 0 |
| `tests/rc1-acceptance.php` | A：无缓存卷 + 变换矩阵 + malformed 源 415；B：持久卷重启后 HIT 且字节/ETag 一致、清空缓存后字节一致重建 |
| `tests/product-acceptance.php` | A：无缓存卷；B：持久卷重启 HIT + 清空安全；C：只读缓存 → `/readyz` 503 而 `/healthz` 200 |
| `tests/docker-acceptance.php` | 141 项检查，含 29 项变换矩阵、版本段新条目、重启/停启、命名卷跨容器复用、缓存清空可重建 |
| `tests/crash-recovery.php` | 四类破坏场景：忙碌 worker SIGKILL、容器 SIGKILL、池 supervisor SIGKILL、在途 graceful stop。每次 kill 以 supervisor 日志与 worker staging 文件双重证明落在编码中途，恢复产物与纯净容器逐字节比对，并检查 staging/临时文件零残留 |

失败诊断必须同时给出容器日志的 head 与 tail：就绪轮询会写出数百行 access log，只取 `--tail N` 会把池子启动输出和真正的错误挤出窗口。

`tests/crash-recovery.php` 与 `tests/pool-http.php` 都会在停止后重新读取 `docker port`：OrbStack 上 stop/start 会重新分配临时宿主端口。

## CI 当前配置
`.github/workflows/ci.yml` 在 master/main push、v* tag、PR 触发；test job 将 linux/amd64 对应 ubuntu-24.04、linux/arm64 对应 ubuntu-24.04-arm。每个 runner 构建 development 镜像（Dockerfile 安装 libvips），容器内以 **uid 33** 运行完整测试（`--do-not-cache-result`）与 PHPStan，使权限敏感断言真正执行而不是被跳过；再构建 production 镜像，由宿主 PHP 依次运行 `container-smoke`、`rc1-acceptance`、`product-acceptance`、`docker-acceptance`、`crash-recovery` 五套验收。

image job 依赖 test，QEMU/buildx 构建 linux/amd64、linux/arm64。分支与 PR 只构建并缓存；`v*` tag 才登录 Docker Hub（用户 `allovince` + `secrets.DOCKERHUB_TOKEN`）并推送 `docker.io/allovince/evathumber:<去掉 v 的版本号>` 与 `:latest`，`latest` 正是 README Quick Start 拉取的标签。缺 secret 时该步直接失败并给出 `::error::`，不会产出半发布状态。secret 只存在于 Actions，未读取其值。容器原生库输出 heifload nclx 警告，测试通过不代表无警告。

最近一次远程运行：run `36322300814`（commit `aade6db`，main），`test`（amd64、arm64）与 `image` 三个 job 全部 success，证据归档在 [`bench/results/rc1-ci/`](../bench/results/rc1-ci/)。分支 push 不推送镜像，只有 tag 会。

## 覆盖缺口
尚未完整覆盖所有 resize/codec、动画拒绝、全部 Limits 边界、Accept 各 q/通配符组合、方法限制、来源并发变更。处理中停止与强杀恢复已由 `tests/crash-recovery.php` 在真实容器与真实 HTTP 上覆盖。HEAD/304 已由独立容器 HTTP 验收覆盖，跨进程准入与处理超时已有回归；满槽准入另经 `tests/compose-admission.php` 在真实 Compose HTTP 入口验证。硬 OOM 隔离尚无证据：现有的是 RSS 采样回收加有界拒绝。`access=random` 的内存代价目前只有单次 28 MPix 峰值测量，没有负载下的 RSS 分布证据。新增行为时按影响选择测试，不冒称完整覆盖。

## 相关
- 配置：`phpunit.xml`、`phpstan.neon`、`.github/workflows/ci.yml`。
- [命令](commands.md)、[首扫待确认](../agent-handbook.md)。
