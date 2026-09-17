# 分支改动报告：feat/v2-cloudinary-compat

## 1. 审查范围与证据等级

生成日期：2026-09-17。用途：交给另一个 Agent 独立 review；本报告不是审查通过结论。

- 目标提交：`315303a49d1ee362c9e13b0713e7453fca6ea1cb`。
- 比较基线：`ad83900cae6954bb4f49be325de41ac8cf0ee82b`（生成时本地 `master`，也是 merge-base）。
- 分支相对基线只有 1 个提交；已经推送到 `origin/feat/v2-cloudinary-compat`。
- 比较范围：上述两个固定 SHA 之间的差异，不是整个 v2 相对 v1 的差异。
- 统计：48 个文件，新增 4670 行、删除 131 行；其中 `composer.lock` 新增 2948 行。不能用总行数代表业务复杂度。
- 报告开始时工作区干净。本报告是随后新增的交接文档，不属于上述 48 文件，尚未提交或推送。

证据分层：

| 等级 | 本报告采用的证据 | 限制 |
|---|---|---|
| 本轮直接核对 | Git SHA、merge-base、提交列表、48 文件统计；核心源码 diff；构建配置 diff；基准脚本、进度文档 | 不等于逐行独立 review |
| 历史验证记录 | 上一轮执行结果及 `docs/progress.md` 的测试、容器、Compose、基准记录 | 本轮没有重跑；未在本报告附带完整原始日志归档 |
| 待验证 | HTTP 压测、真实素材视觉质量、处理中停止等 | 不可写成已通过 |
| 阻塞 | 计划中的独立辅助 diff 核对遇到 provider HTTP 429 限流 | 独立审查未完成，尤其测试断言和全部文档差异仍需 reviewer 逐行核对 |

为按用户要求及时交付，本轮停止继续探索，不执行原先预告的测试/基准复跑，不修改业务代码，也不再次提交或推送。

## 2. 增量摘要及不应误读的范围

本分支的主要增量：

1. 缓存从容量用尽后拒绝转为最老写入优先淘汰，并用共享文件租约保护正在发送的条目。
2. 冷缓存请求增加 8 个跨进程准入槽位；仍然只有一个全局 producer，获准等待者最多轮询 250ms。
3. HTTP 接入持有租约的响应类；版本进入缓存身份；允许忽略 SDK analytics 标量查询参数；完整 URI 限长。
4. 支持末尾分段的 q/f 投递参数；增加 JPEG/WebP/AVIF 的本地自动质量启发式。
5. 跟踪 CLI 入口及依赖锁文件，修正 Docker/Compose 可运行性和 CI 架构测试方式。
6. 增加缓存、失败恢复、自动质量及 HTTP 回归测试、容器验收脚本和实验性基准。

**不是本分支新实现的内容**：URL 解析器、`IsolatedProcessor`、`LocalSource`、`public/index.php`、`docker/Caddyfile` 在本次 diff 中没有修改。相关现有能力可能被新增测试覆盖，但不能全部归功于此提交。生产依旧每次 miss 启动 CLI 子进程；没有切换为持久图片处理 worker。

**不是完整 Cloudinary 替代品或发布完成声明**：不承诺像素/字节一致、历史版本快照、按键 single-flight、生产吞吐或长期 worker 稳定性。六阶段产品目标仍有未完成门槛。

## 3. 核心行为变化

### 3.1 缓存生命周期与容量

涉及 `src/Cache/CacheEntry.php`、`src/Cache/DiskCache.php`。

原行为：条目仅携带路径等元数据；缓存满后返回容量错误；miss 遇全局锁忙立即拒绝。

新行为：

- `CacheEntry` 从 readonly 类变成持有文件资源租约的对象，由对象生命周期管理共享锁。reviewer 应核对构造签名、属性修饰与资源释放对库调用者的兼容性，不能视作纯内部重构。
- 读取条目时打开文件、尝试非阻塞共享锁，通过文件句柄状态和当前路径 inode 等检查确认条目可用；拒绝空文件及已观察到的符号链接。
- 产物成功生成且大小有效后才进入 `makeRoom()`，避免 producer 失败先淘汰旧条目。
- 扫描合法缓存文件，按 mtime、路径排序，先淘汰最老写入条目；**不是 LRU**，命中不会更新访问时间，也没有磁盘 TTL。
- 淘汰需取得非阻塞独占锁；正在被响应共享租用的条目跳过。不能腾出足够空间返回 `503 cache_busy`。
- 单产物为空或超过缓存总容量返回 `507 cache_full`；发布仍使用临时文件与 rename。
- 生成异常时清理本次临时文件；在全局生产锁下清理既有 `.tmp-` 孤儿。

需审查：租约所有权与释放路径、容量计数边界、文件系统错误处理、扫描/排序成本、对外接口兼容性。inode/锁检查不能被描述成任意文件系统和任意外部写入者下的完整一致性证明。

### 3.2 固定槽位准入

涉及 `DiskCache::remember()`、`admit()` 和 ADR 0001。

- 每缓存根使用 `.admission-0.lock` 至 `.admission-7.lock` 共 8 个固定文件，依次尝试非阻塞独占 flock。
- 命中不占准入槽，也不等待 `.publish.lock`。
- 满槽立即返回 `503 processor_busy`；获准 miss 才进入全局生产锁等待循环。
- 250ms 是等待生产锁的预算，不是整次转换/HTTP 响应的总时限；轮询间隔上限约 10ms，并复查同键产物。
- 获得全局锁后再查缓存，防止重复生产已发布产物。
- 所有路径通过 finally 释放生产锁及准入资源；进程死亡时由内核关闭描述符并释放 flock。
- 理论上最多 1 个 producer 和 7 个获准等待者；不是同时处理 8 张图片。
- 固定锁文件要求运行期不删除、不替换，依赖参与者遵守相同协议及支持 flock 的本地文件系统。
- 不限制 HTTP 服务器自身的请求排队，不保证公平调度，也不是分布式锁。

重点核对：异常分支是否泄漏资源、槽位是否覆盖全部 miss 路径、锁文件生命周期是否在部署说明中足够明确，以及 250ms/8 槽是否适合实际请求时长。

### 3.3 HTTP 投递与身份

涉及 `src/Http/Kernel.php`、新增 `src/Http/CachedFileResponse.php`。

- `CachedFileResponse` 继承 Symfony `BinaryFileResponse`，持有 `CacheEntry`，在 `sendContent()` 的 finally 中解除引用；对象销毁亦结束其持有关系。
- 目标是保护准备/发送期间文件不被本缓存实现淘汰。没有证据支持此前提交说明里的“zero-copy”性能说法；继承文件响应不等于已证明零拷贝。
- Kernel 将该响应接入实际返回路径，保留 Content-Type、nosniff、HIT/MISS、ETag、缓存响应逻辑。
- 除 health 路径的既有提前返回外，检查完整 Request URI 长度，超限返回 `414 url_too_long`。
- 不再拒绝所有查询参数：允许解析结果为字符串的 `_a`、`_i`；其他名称或非字符串值拒绝。
- 缓存身份从 `evathumber-2-policy-1` 升为 `evathumber-2-policy-3`，加入 `AutoQuality::POLICY` 和 URL version；继续包括来源 identity、规范化变换、最终格式、limits。
- 版本变化产生新条目，但不会保存原图历史快照。策略升级也会让旧条目不再命中，由后续容量回收处理。

需审查：HEAD/304/异常/未调用 sendContent 时的租约生命周期、Symfony 实际发送流程、原图被替换时身份与转换的一致性、codec 版本未进入身份的影响，以及 analytics 参数处理是否符合约定。

### 3.4 投递参数规范化

涉及 `src/Transformation/ParameterRules.php`、`Transformation.php`。

- q 接受整数 1–100，新增 `auto` 和 `auto:best/good/eco/low`；`auto` 规范化为 `auto:good`。
- 遇到首个含 q/f 的步骤后，后续步骤仅可含 q/f，且不能重复已有投递键；合法投递步骤合并。
- 因而 `q_80/f_webp`、`f_webp/q_80` 可以被接受；投递后继续像素变换仍拒绝。
- 合并要与已有规范化、步骤顺序、直接构造 `Transformation` 的行为保持一致。

需审查：混合像素/投递步骤、重复键、不同分段方式是否产生正确 canonical，以及 `f_auto` 最终协商结果与缓存身份是否一致。

### 3.5 自动质量

涉及新增 `src/Image/AutoQuality.php`、`src/Image/Pipeline.php`。

- 策略标识 `edge-density-v1`；只支持 JPEG/WebP/AVIF 自动质量。
- JPEG/WebP/AVIF 的基础 Q 分别为 72/68/48；best/good/eco/low 偏移为 +10/0/−12/−25。
- 最终输出图采样至最长边不超过 256，转换 sRGB，透明样本使用白底，转 uchar 并物化。
- 计算水平/垂直相邻像素绝对差平均值，归一化后最多增加 12 Q，最终夹在 1–95。
- Pipeline 对自动质量路径先物化最终输出图，避免顺序源被分析与编码重复遍历；代价是完整输出图内存占用，不只是 256×256 样本内存。
- JPEG 白底 flatten 仍以 hasAlpha 为前提；普通数字 q 默认仍为 80。
- PNG/GIF 自动质量、未知档位、sensitive 不在支持范围。

需审查：色彩空间/位深/透明图/极小尺寸语义，格式基础 Q 与档位关系，采样是否代表感知细节，输出物化与大图 RSS 限额是否匹配。**这是本地启发式，不是 Cloudinary 感知算法；合成图 RGB MAE 不能充当视觉验收。**

## 4. 交付、依赖及实验工具

- `bin/transform.php` 纳入版本控制，`bin/.gitignore` 加例外。它是隔离执行链需要的入口；请重点核对和现有 `IsolatedProcessor` 的协议是否一致。
- `composer.lock` 纳入跟踪，`.gitignore` 移除对它的忽略。`composer.json` 未改：不是此次新增依赖声明，但锁定了解析结果，需要单独核对依赖版本和安全公告。
- `Dockerfile` 固定 FrankenPHP 基础镜像及 Composer 镜像 digest；生产阶段 `setcap -r`，用于支持 cap-drop ALL 场景。apt 包和 Dockerfile frontend 仍未完全固定。
- `.dockerignore` 补足 src/public 递归、bin/docker 父目录、tests、测试配置和 bench 的构建上下文白名单。
- `compose.yaml`：Caddy tmpfs 指定 uid/gid 33，增加 cap_drop ALL，healthcheck 从错误的 8080 改为 8081。只读根、资源限额等并非全部在此次新增。
- `.github/workflows/ci.yml`：从未真正分流的架构矩阵改为 ubuntu-24.04 与 ubuntu-24.04-arm；关闭 fail-fast，在对应架构 development 容器内执行测试/静态检查，再构建 production 镜像并运行 HTTP smoke。
- `phpstan.neon` 删除指向不存在目录的排除配置，不是降低静态分析级别。
- `bench/run.php` 对比 isolated 与 persistent；合成 1600×1000 JPEG 转 400×300 WebP，串行，排除 2 次预热后统计分位数，输出 CPU/RSS/摘要。
- `bench/worker.php` 是实验用 stdin/stdout 作业进程，不具备生产级隔离、作业回收和有界并发，未接入 HTTP。
- `bench/quality.php` 对三种合成图、三种格式、数字/自动质量档位测体积、RGB MAE 和编码时间；当前进程，无 HTTP、无缓存。

## 5. 完整文件清单（48 个）

M = 修改；A = 新增。文档条目描述其同步主题，不代表本轮逐行独立审查已经完成。

| # | 状态 | 文件 | 改动主题 |
|---|---|---|---|
| 1 | M | `.ai/memory.md` | 当前产品推进焦点转向 progress 文档 |
| 2 | M | `.dockerignore` | 补齐构建上下文白名单 |
| 3 | M | `.github/workflows/ci.yml` | 真实架构 runner、容器测试、生产 smoke |
| 4 | M | `.gitignore` | 不再忽略 composer.lock |
| 5 | M | `Dockerfile` | 两个镜像 digest、生产去除文件 capability |
| 6 | M | `README.md` | 英文能力与交付边界同步 |
| 7 | M | `README.zh-CN.md` | 中文能力与交付边界同步 |
| 8 | A | `bench/quality.php` | 自动质量合成图实验 |
| 9 | A | `bench/run.php` | 执行模型串行对照基准 |
| 10 | A | `bench/worker.php` | 实验持久进程 |
| 11 | M | `bin/.gitignore` | 跟踪 transform.php |
| 12 | A | `bin/transform.php` | 隔离变换 CLI 入口纳入提交 |
| 13 | M | `compose.yaml` | tmpfs 所有权、cap-drop、健康端口 |
| 14 | A | `composer.lock` | 锁定依赖解析结果 |
| 15 | M | `docs/agent-handbook.md` | 历史基线与当前状态边界 |
| 16 | A | `docs/architecture/adr/0001-cache-admission.md` | 8 槽与单 producer 的取舍 |
| 17 | M | `docs/architecture/boundaries.md` | 更新缓存/执行责任边界 |
| 18 | M | `docs/architecture/overview.md` | 更新主路径与限制 |
| 19 | M | `docs/components/Cache/README.md` | 身份、租约、淘汰、准入 |
| 20 | M | `docs/components/Http/README.md` | HTTP 查询与响应变化 |
| 21 | M | `docs/components/Image/README.md` | 自动质量与实验入口 |
| 22 | A | `docs/components/Image/auto-quality.md` | 本地质量策略与测量限制 |
| 23 | M | `docs/components/Transformation/README.md` | q_auto、投递链规范化 |
| 24 | M | `docs/components/Url/README.md` | 版本及 URL 语义边界 |
| 25 | M | `docs/development/commands.md` | 验证/基准命令与状态 |
| 26 | M | `docs/development/testing.md` | 测试覆盖与验证范围 |
| 27 | M | `docs/index.md` | 增加 progress/ADR 导航 |
| 28 | M | `docs/operations/deploy.md` | Docker/Compose 与 smoke 证据 |
| 29 | M | `docs/operations/runtime.md` | 运行行为与排障边界 |
| 30 | A | `docs/progress.md` | 六阶段状态、验证、基准及剩余门槛 |
| 31 | M | `phpstan.neon` | 删除无效排除路径 |
| 32 | M | `src/Cache/CacheEntry.php` | 共享资源租约 |
| 33 | M | `src/Cache/DiskCache.php` | 准入、等待、淘汰、受保护条目 |
| 34 | A | `src/Http/CachedFileResponse.php` | 响应持有租约 |
| 35 | M | `src/Http/Kernel.php` | URI/analytics/身份/响应接线 |
| 36 | A | `src/Image/AutoQuality.php` | 格式相关边缘密度启发式 |
| 37 | M | `src/Image/Pipeline.php` | 自动质量接入与物化 |
| 38 | M | `src/Transformation/ParameterRules.php` | q_auto 合法值与默认档位 |
| 39 | M | `src/Transformation/Transformation.php` | 合并末尾投递步骤 |
| 40 | A | `tests/compose-admission.php` | 满槽状态下真实 HTTP HIT/503/恢复 |
| 41 | A | `tests/container-smoke.php` | 生产容器 HTTP、就绪、空闲退出 |
| 42 | A | `tests/v2/AutoQualityTest.php` | 自动质量档位、图像及编码回归 |
| 43 | A | `tests/v2/CacheConcurrencyTest.php` | 同键复用、跨进程准入与强杀恢复 |
| 44 | A | `tests/v2/CacheLifecycleTest.php` | 容量淘汰、租约、失败清理 |
| 45 | M | `tests/v2/HttpTest.php` | 版本/analytics/投递链/响应租约等 |
| 46 | M | `tests/v2/PipelineTest.php` | 真实编码与新投递语义 |
| 47 | A | `tests/v2/ProcessorFailureTest.php` | 超时、异常退出、结构化拒绝后恢复 |
| 48 | M | `tests/v2/StorageAndUrlTest.php` | URL 解析及规范化边界回归 |

## 6. 验证记录与不能推出的结论

### 历史功能验证

以下来自前一轮执行记录和 progress，不是本轮重跑：

- 宿主、arm64 development 容器、amd64 development 容器：35 tests / 387 assertions；PHPStan level 8 通过。
- amd64 是 macOS/OrbStack 上模拟架构，不是原生 amd64 性能验证。
- 原生 arm64 连续 5 次 smoke 和后续 amd64 smoke 通过；记录就绪 9–146ms，最多 1 次重试。
- smoke 覆盖非 root、只读、cap-drop ALL 环境下真实变换、尺寸/MIME、MISS/HIT、HEAD、304、f_auto/Vary、analytics；空闲 SIGTERM exit 0、无 OOM。
- Compose Quick Start 达到 Healthy，真实产物为 20×13 JPEG；重启后命名缓存卷 HIT，正文和 ETag 一致。
- Compose 准入脚本通过：外部持有全部槽位时 HIT 200、冷键 503 processor_busy 与 Retry-After，释放后 MISS 200。
- 最后一次历史 `git diff --check` 通过。

局限：

- 原始历史 amd64 20s 启动探测失败根因未定位。探测改成每次最多 1s、总预算 20s 和增加证据输出，**不证明历史故障已修复**。
- 手工占槽的 HTTP 验收不是实际高并发负载；进程强杀恢复测试不是处理中优雅 drain。
- 容器测试曾有 heifload nclx 原生警告，不能宣称运行无警告。
- OrbStack 快照错误通过重建绕过，不是修复应用缺陷的证据。
- 本轮未查询推送后的远程 CI 状态；文档中的“尚未远程执行”是历史状态，不能直接当作当前 GitHub 状态。

### 历史基准（非本轮、无 master 对照）

macOS arm64 / PHP 8.5.10，合成图，串行转换：

| 模型 | 样本 | p50 / p95 / p99 ms | 串行 jobs/s | 子进程峰值 RSS MiB |
|---|---:|---|---:|---:|
| isolated | 30 | 97.183 / 102.771 / 103.378 | 10.218 | 62.969 |
| persistent | 30 | 7.462 / 8.085 / 8.691 | 132.821 | 70.203 |
| persistent | 500 | 7.418 / 8.277 / 9.914 | 132.582 | 85.563 |

三轮输出摘要记录一致。该实验支持“在此任务上进程启动开销明显”，不支持“本分支比 master 快”“生产吞吐提升到上述数值”“长期无泄漏”或“自动质量无性能回归”。无 HTTP/缓存冷热/并发/真实素材对照，RSS 为子进程高水位，不是服务总内存或逐请求曲线。

## 7. 必须纠正的历史叙述

1. 提交消息写了拒绝 q_auto，但实际本分支接受上述自动质量档位；以 `ParameterRules` 和 `AutoQuality` 为准，不能按提交消息审查。
2. 提交消息还包含 IsolatedProcessor 硬化、Caddy 两 worker 等背景；这些文件在此 diff 未变，不应算成本次新增实现。
3. 此前回复称 GitHub 提示的默认分支 2 个 Dependabot 公告“与本分支无关”，这个结论没有核验依据。应检查具体公告和锁定版本；本轮没有查公告，也不声称已解决。
4. “四项 todo 完成”指前一轮限定增量，不代表六阶段产品验收结束。
5. `.ai/memory.md` 中“未授权 commit”描述形成于后来授权提交之前，不能用它否认已发生的用户授权和推送事实。本轮不顺手改历史文档或提交消息。

## 8. 建议 review 顺序和交付要求

优先检查（这些是审查问题，不是已确认漏洞）：

1. **正确性与兼容性**：CacheEntry 公共接口变化；共享租约能否覆盖 Symfony 的实际投递生命周期；失败分支资源回收；缓存身份与原图修订一致性。
2. **资源和可用性**：8 槽/单 producer/250ms 是否真正限定缓存层工作；淘汰复杂度；自动质量物化大图的内存；超时后的恢复路径。
3. **变换语义**：投递步骤归并、重复键拒绝、canonical 等价性、自动格式协商和自动质量格式限制。
4. **交付可复现性**：CLI 确实进入构建、lock 与安装一致、镜像 digest/架构、capability/端口/tmpfs 所有权、CI runner 可用性。
5. **测试质量**：逐行检查断言是否覆盖真实行为，尤其时间阈值、跨进程同步、清理逻辑；避免以“测试数增加”代替有效覆盖。
6. **文档真实性**：README 是否超出实际支持；历史成功与失败是否混写为最终通过；实验 worker 是否被误当生产能力。

独立 Agent 建议输出：

- Review 对象的 base/head SHA，实际检查范围，运行过的验证与未执行项。
- Findings 按严重性排序，每项给文件与行号、问题、影响、代码/测试依据、建议修复；区分已确认缺陷与待验证疑点。
- 仅给防御性代码审查和隔离本地回归建议，不针对第三方系统执行验证。
- 无发现也要列残余风险，不因已有绿测或本报告描述而直接批准。
- 单列“合并前必须修复”和“后续发布门槛”；不要自动扩展为重写，不要自动提交、推送或发布。

仍未完成的产品门槛：真实 HTTP 并发负载、处理中停止、真实摄影/文字/透明素材视觉校准、大图 RSS、源一致性、codec 身份、apt/frontend 可复现性、远程 CI/GHCR 验收。

## 9. 相关入口

- [当前产品进度与历史证据](progress.md)
- [工程文档索引](index.md)
- [缓存准入 ADR](architecture/adr/0001-cache-admission.md)
- [自动质量策略与测量限制](components/Image/auto-quality.md)
- [测试说明](development/testing.md)
- [部署说明](operations/deploy.md)

报告结束：分支已经包含可验证增量，但本报告没有独立 review 通过结论。独立 diff 核对因限流未完成；本轮无测试/基准复跑。
