# 产品推进状态
## 最终目标
让使用 Cloudinary Image Transformation URL 的项目能够可靠迁移到高性能、自托管 EvaThumber。以可运行实现、兼容语义证据、真实基准和生产验证为验收，不以接受参数或更新 README 代替支持。
## 当前状态
**未达到可发布标准。六阶段均未宣称完整完成。** 当前已完成的增量：
- URL/Asset：补齐 cloud name、版本边界、嵌套 public ID、投递扩展名、变换链的解析用例；原有解析器无需更改。
- HTTP：PNG 原图通过 `.jpg` 投递；参数顺序及 jpeg/jpg 扩展名共享条目；标量 `_a`/`_i` analytics 可忽略，其他查询参数明确拒绝；完整 URI 限长。
- 版本纳入缓存身份，版本变化产生新条目。当前策略标识为 `evathumber-2-policy-3`，含自动质量策略 `edge-density-v1`。
- 缓存容量按最老写入时间淘汰，不再永久满盘拒绝；共享租约保护正在投递的文件，忙时 503 + Retry-After；单产物超过总容量仍 507。
- HTTP 响应持有租约至 sendContent() 结束或销毁；producer 失败不淘汰已有条目，临时文件清理。
- 修复 PHPStan 指向不存在目录的排除配置。
- 并发 miss：每个缓存根固定 8 个跨进程准入槽位（含唯一 producer），满槽立即 503 processor_busy；获准 miss 忙锁最多 250ms 复查同键产物（跨进程验证），超时仍 503；命中绕过槽位与锁；强杀 producer 后锁释放、孤儿临时清理。不是按键 single-flight，不限制 HTTP 服务器队列，见[准入 ADR](architecture/adr/0001-cache-admission.md)。
- Transformation：末尾 q/f 允许分段（`q_80/f_webp`、`f_webp/q_80`），合并为单一投递步骤；重复投递参数或投递后再执行像素步骤仍拒绝。
- q_auto 四档已接入 JPEG/WebP/AVIF；依据格式和最终图像边缘密度选择 Q，默认 good。PNG/GIF、sensitive/未知档位明确拒绝。是可运行的本地启发式，不是 Cloudinary 感知算法；真实素材视觉验收仍未完成，详见 [策略与实测](components/Image/auto-quality.md)。
- 执行模型实验（非生产）：`bench/worker.php` + `bench/run.php` 已跑通隔离/持久进程对照，数据与限制见下表；生产模型未切换。
## 当前验证
本机 macOS / PHP 8.5.10 / PHPUnit 12.5.35：
- `vendor/bin/phpunit` 与 `composer test`：35 tests / 387 assertions，通过（最近宿主 composer test 4.446s，PHPUnit 进程峰值 18MB；不是服务性能数据）。
- Docker / OrbStack：arm64 原生、amd64 模拟，PHP 8.5.10 / libvips 8.14.1；两个 development 镜像均 35 tests / 387 assertions、PHPStan 通过。有 heifload nclx 原生警告，不宣称无警告。
- `ProcessorFailureTest`：真实 Process 超时、异常退出、结构化拒绝均不发布部分数据，清理临时文件并允许下一次正常变换；本机 3 tests / 33 assertions。
- `tests/container-smoke.php`：两个 production 镜像均通过 nonroot/read-only/cap-drop ALL 的真实网络变换、MIME/尺寸、MISS/HIT、HEAD、304、f_auto/Vary、analytics；空闲 SIGTERM exit 0、无 OOM。就绪探测改为单次最多 1s、总预算 20s，并记录重试与就绪耗时；原生 arm64 连续 5 次及最新 amd64 均通过（就绪 9–146ms，最多 1 次重试）。历史 amd64 20s 超时未复现、根因未定位，不能宣称已修复。不涵盖处理中 drain。
- 完整 Compose Quick Start 已实跑（独立项目、隔离端口、只读原图绑定、命名缓存卷）：Healthy、真实变换 20×13 JPEG、重启后命名卷 HIT 且字节/ETag 一致；修复 Caddy tmpfs 缺 uid/gid=33 导致的 autosave/存储清理权限错误。满槽准入经 `tests/compose-admission.php` 真实 HTTP 验证：HIT 200、冷键 503 processor_busy + Retry-After、释放后 200 MISS。
- CI 改为 ubuntu-24.04/ubuntu-24.04-arm 实际 runner 分流、容器内 libvips 测试与生产 smoke；尚未远程执行、未发布。
- 最新复验发现 amd64 宿主健康探测连续两次未在 20s 内就绪；日志显示 FrankenPHP 已启动、没有对应请求访问记录。独立容器内 curl、宿主 curl 与 PHP 客户端随后均 200，原样 smoke 再次通过。根因尚未确定，保留为验收不稳定项；不能由重跑通过消除失败，也不能宣称最终双架构验收稳定通过。
- `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`：通过。
- 新增投递测试曾复现 Kernel 的未接线响应类导致 500；修复后验证正文逐字节一致、发送期间拒绝淘汰、发送后可回收。
- 新增版本测试曾复现 v124 命中 v123；加入版本身份后通过。
- 末尾投递链测试曾复现 q_80/f_webp 被拒绝；合并后通过真实编码验证。HTTP 的 q_80/f_auto、f_auto/q_80 与同一末尾组件共享 ETag/缓存，重复键与投递后的像素步骤仍拒绝。
- 尚未验证真实网络/FrankenPHP 并发负载、处理中停止、原生 amd64 性能和外部 Cloudinary 像素对照；双架构本地 HTTP 冒烟不代替这些验收。
## Benchmark 关键数据
macOS arm64 / PHP 8.5.10；`bench/run.php` 使用合成 1600×1000 条纹 JPEG，执行 `c_fill,w_400,h_300/f_webp,q_80`；并发 1，每轮额外预热 2 次。复现入口：`php -d ffi.enable=true bench/run.php isolated 30`、`php -d ffi.enable=true bench/run.php persistent 30`、`php -d ffi.enable=true bench/run.php persistent 500`。

| 模型 | 样本 | p50 / p95 / p99 (ms) | 串行 jobs/s | 子进程 CPU (s) | 总墙钟 (s) | 子进程峰值 RSS (MiB) |
|---|---:|---|---:|---:|---:|---:|
| isolated | 30 | 97.183 / 102.771 / 103.378 | 10.218 | 2.987 | 3.134 | 62.969 |
| persistent | 30 | 7.462 / 8.085 / 8.691 | 132.821 | 0.355 | 0.337 | 70.203 |
| persistent | 500 | 7.418 / 8.277 / 9.914 | 132.582 | 4.245 | 3.892 | 85.563 |

- 延迟/吞吐排除预热；CPU/总墙钟包含预热，CPU 只统计子进程。RSS 是子进程高水位，不是服务总内存或逐作业增长曲线。
- 三轮产物 SHA256 一致：`90518f3eb3b95655e113eca7c1aca57bc642f844975077dcf3be06081826dc5e`。
- 仅支持启动开销显著的结论；样本少且只有合成图，不能外推生产吞吐。500 样本运行的 RSS 高于 30 样本，不足以判断泄漏或长期稳定。
- 实验是可信本地 stdin/stdout JSON 行协议；驱动有读超时和失败清理，但 worker 不具备生产级作业超时、回收、恢复或有界并发。不可直接用于 HTTP。
- 尚无 HTTP/冷热缓存/并发/真实图片语料基准。不得将 PHPUnit 时间或内存作为服务指标。
## 尚未解决
1. 资产：版本不是历史快照，不能宣称 immutable；文件修订检测仍依赖 stat；明确 source 替换与转换之间的一致性边界；扩充实际迁移语料。
2. 执行：仍为每次 miss CLI 子进程；缓存层准入已限 8 槽（含 producer），忙锁 miss 限 250ms 轮询后 503。持久 worker 已有启动开销对照（见上），但无超时/回收/恢复/有界并发，未接入生产。按键 single-flight 等待未实现。
3. 缓存：扫描/排序淘汰复杂度、跨进程压力测试、codec 版本身份、Last-Modified 重建语义需验证；无磁盘 TTL。
4. 兼容：q_auto 各档已有本地内容自适应实现与三格式编码验证，真实摄影/文字/透明素材的视觉校准仍待完成；PNG/GIF 自动质量未支持。现有 resize/gravity/ar/dpr/chains/f_auto/background/rotate/effect 边界见模块文档；不承诺 Cloudinary 字节一致。
5. 性能：需真实 fixture 与可复现冷/热缓存、并发基准，分析 libvips、IPC、编码与缓存开销。
6. 发布：基础/Composer digest、双架构本地构建/测试/HTTP 验收、CI 架构接线及双语 README 边界修正已完成；完整 Compose Quick Start 已本地实跑。apt/构建 frontend 未固定，CI/GHCR 未远程验收；兼容语料与生产防护验收未完成。未经要求不 commit 或发布。
## 下一阶段
剩余验收门槛：真实 HTTP 并发负载、处理中停止、自动质量真实素材视觉校准、大图 RSS、源一致性、codec 身份、apt 可复现性和远程 CI。不要重新跑绿测代替这些实现，也不把本地容器通过等同于六阶段结束。
## 入口
[文档索引](index.md) · [Cache](components/Cache/README.md) · [Http](components/Http/README.md) · [Url](components/Url/README.md)
