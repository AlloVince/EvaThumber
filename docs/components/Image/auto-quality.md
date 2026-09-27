# 本地自动质量
## 契约与兼容边界
`q_auto` 规范成 `q_auto:good`；支持 best/good/eco/low，仅对 JPEG/WebP/AVIF 输出选择 Q。PNG/GIF 明确 400，不静默忽略自动质量。显式整数 q 与省略 q（80）保持原行为。
Cloudinary [官方质量参数](https://cloudinary.com/documentation/transformation_reference_q_quality.md)（2026-09-17 核对）定义这些档位及默认 good；EvaThumber 只兼容语法与档位意图，**没有复刻其感知优化算法**。不支持 sensitive、自动切换格式、Save-Data 自动降档；请求头不参与本地质量选择。
## 策略 edge-density-v1
- 在所有像素变换后分析最终图像；JPEG 先铺白，WebP/AVIF 仅对分析样本铺白，保留输出 alpha。
- 缩小至长边最多 256（不放大），转 sRGB/uchar，计算 RGB 横向/纵向相邻像素绝对差的均值；只有一维可比较时仅取该轴，1×1 细节量为零。
- 细节量 = min(1, 相邻差均值 / 32)，内容增量 = round(12 × 细节量)。缩采样可能抹掉细线/文字，不能据此保证感知质量。
- 基准 Q：JPEG 72、WebP 68、AVIF 48；档位偏移 best +10、good 0、eco −12、low −25；最终夹在 1–95。
- 同图同格式的选中 Q 满足 best ≥ good ≥ eco ≥ low；**不保证文件字节数或感知误差单调**。跨 codec 的 Q 不可直接比较；常量为本地启发式初值，未经过摄影语料或人工视觉校准。
- Pipeline 从 SourceImage 的不可变压缩副本构建 lazy 最终变换图，分析最多 256 长边样本后释放图，再从**同一副本**重建相同变换图编码。仅 AutoQuality 的小样本显式 copyMemory；不再显式实体化整张最终输出，也不回读已消费的 sequential 图。视觉算法、常量与 POLICY 不变。libvips 的旋转/loader/codec 仍可能内部实体化，256 样本上限不等于整个任务内存上限。HTTP 仍有处理子进程截止时间；库调用无硬内存/时间隔离。
- HTTP 缓存策略为 policy-3，纳入 AutoQuality::POLICY；默认与显式 good 共享 canonical，不同档位分键。策略升级会使此前缓存变冷。
## 可复现实测
入口 `php -d ffi.enable=true bench/quality.php`。macOS arm64 / PHP 8.5.10，640×400 合成纯色、RGB 条纹、8px 黑白条纹；每组合 1 次预热 + 10 次样本，直接 Pipeline，无 HTTP/缓存。脚本输出全部四档与 q80 的大小、RGB MAE、p50/p95。

| 图像/格式 | good 选中 Q | good 字节 | good RGB MAE (0–255) | good p50/p95 ms | q80 p50 ms |
|---|---:|---:|---:|---|---:|
| 纯色 JPEG | 72 | 4607 | 0 | 2.914 / 4.314 | 1.173 |
| 纯色 WebP | 68 | 514 | 0 | 7.711 / 8.519 | 5.922 |
| 纯色 AVIF | 48 | 364 | 0 | 7.785 / 8.178 | 5.791 |
| RGB 条纹 JPEG | 72 | 9646 | 0.458 | 3.509 / 5.089 | 1.718 |
| RGB 条纹 WebP | 68 | 2074 | 0.592 | 10.304 / 11.037 | 9.215 |
| RGB 条纹 AVIF | 48 | 1177 | 0.387 | 9.977 / 10.597 | 11.617 |
| 黑白边缘 JPEG | 84 | 11607 | 0 | 3.151 / 3.813 | 1.347 |
| 黑白边缘 WebP | 80 | 704 | 0 | 8.590 / 9.256 | 5.951 |
| 黑白边缘 AVIF | 60 | 457 | 0 | 10.108 / 11.769 | 8.390 |

纯色 WebP low 516 字节反而略大于 good 的 514；黑白边缘 JPEG best 比 good 更大但两者 MAE 均为 0。表明不能承诺最小文件。RGB MAE 不是感知度量；合成数据不能证明真实摄影、文字或透明素材的视觉质量，生产质量验收仍待完成。
## 内存执行策略对比（2026-09-17）
入口 `php -d ffi.enable=true bench/quality-memory.php [image] [iterations] [expression]`；默认 upload/blend.png、7 个进程/模式、q_auto。首个进程丢弃，其余每次独立 PHP worker，无 HTTP/缓存；格式 JPEG/WebP/AVIF。表达式可传 `w_4096/q_auto` 等，不能含 f。worker 反射调用同一个私有 `Pipeline::prepare` 图构建器以比较旧 copyMemory 与新生产 Pipeline，避免复制视觉变换实现；prepare 改名需同步基准和回归。
PHP 8.5.10 / libvips 8.18.6 / Darwin arm64 / concurrency=2、operation cache=0。时间从 resolve（含快照）到编码结束，不含 PHP bootstrap；RSS 用 getrusage.ru_maxrss（macOS bytes、Linux KiB），包含 bootstrap、快照、原生库峰值，**不是 PHP memory_get_peak_usage**。输出重解码/MAE 在峰值与时间采集之后，避免参考图污染数值。每策略固定顺序，文件系统已暖，无置信区间；p50 使用排序后的上中位样本，max 不冒称 p95。小图 blend 6 个有效样本，其余各 3 个。
素材是工作目录现有文件，未下载、未声称许可证/摄影来源：
- `upload/demo.jpg`，300×200 RGB；SHA256 `221a14a31070065a6b5e83b7e076e0d31fbcfa14edfee49172bb6038a1c54788`；`data/images/demo.jpg` 与其重复，不当作独立素材。
- `upload/face.jpg`，300×433 RGB；SHA256 `f8dd45e4b3e1921b2804226957d1585cdebb3b7a21aae34e46ccb6c41779aacd`；名字不代表已核实内容/来源。
- `upload/blend.png`，300×200 RGBA；SHA256 `87ed5009e86a097fce5baa9df37f8c3f47779ec360b312b1eddd326dd2e8af5a`。
- 大图为 blend 经 libvips resize(4096/300) 保存的 **4096×2731 放大衍生图**，不是原生高分辨率样本：PNG compression=6，1,291,159 bytes；同一图铺白、JPEG Q90/strip，517,191 bytes。生成/测量用临时文件，未纳入仓库。

表中 `copy → rebuild` 为两种执行策略；RSS 是每进程峰值的中位数（MiB），时间是 p50 ms。

| 输入 | 输出 | Q / 字节（两者相同） | RGB MAE（两者相同） | RSS MiB | p50 ms |
|---|---|---|---|---|---|
| blend 原图 | JPEG | 72 / 3318 | 0.9735 | 51.5 → 51.7 | 6.81 → 7.60 |
| blend 原图 | WebP | 68 / 976 | 1.1936 | 53.3 → 53.7 | 9.14 → 10.20 |
| blend 原图 | AVIF | 48 / 1072 | 0.9155 | 62.8 → 63.2 | 11.90 → 12.49 |
| face 原图 | JPEG | 76 / 26022 | 4.1895 | 51.5 → 51.8 | 7.29 → 7.74 |
| face 原图 | WebP | 72 / 19564 | 3.9444 | 52.3 → 52.8 | 14.47 → 15.23 |
| face 原图 | AVIF | 52 / 13253 | 4.4079 | 61.3 → 62.0 | 35.06 → 34.73 |
| demo 原图 | JPEG | 75 / 10457 | 4.2184 | 51.1 → 51.6 | 6.45 → 6.35 |
| demo 原图 | WebP | 71 / 7426 | 4.2633 | 51.7 → 52.4 | 10.48 → 10.46 |
| demo 原图 | AVIF | 51 / 5003 | 4.5917 | 58.5 → 58.8 | 18.22 → 18.59 |
| 大 PNG | JPEG | 72 / 210623 | 0.6903 | 99.1 → 77.7 | 46.57 → 67.30 |
| 大 PNG | WebP | 68 / 42904 | 0.6795 | 271.8 → 245.8 | 381.63 → 384.80 |
| 大 PNG | AVIF | 48 / 24097 | 0.5725 | 468.8 → 432.8 | 426.44 → 438.83 |
| 大 JPEG | JPEG | 72 / 218115 | 0.5894 | 96.3 → 73.1 | 41.16 → 43.73 |
| 大 JPEG | WebP | 68 / 45204 | 0.7717 | 143.4 → 126.9 | 320.34 → 324.50 |
| 大 JPEG | AVIF | 48 / 24017 | 0.4838 | 333.8 → 314.5 | 325.18 → 334.28 |

各对应结果输出 SHA256 一致（不只是 Q 一致），透明 WebP/AVIF 保留 alpha，MAE 用白底 RGB 而非感知评分。新版 runner 自动断言全部样本的 Q/大小/摘要/MAE/alpha/尺寸一致，并输出原始样本、输入摘要。首次测量报告在 `/tmp/qmem-{blend,big-png,big-jpg,face,demo}.json`；前三个为增加 raw_samples/自动等价断言之前的报告结构，表中不虚构原始样本。临时路径不是持久 CI artifact。
**决定采用 rebuild-lazy**：大图去掉整张输出副本，实测节省 16.5–36 MiB 峰值中位数，无视觉变化/依赖；代价是重做解码和变换，最明显的大 PNG→JPEG 增加约 44.5% 延迟。小图没有内存收益、略增开销；不加未经充分校准的尺寸阈值或双策略公共开关。不声称所有场景更快、RSS 有硬上限或 AVIF 可在紧内存容器内运行。旋转/复杂变换的 RSS、冷盘/满源字节限额、常驻池并发/长期 RSS、Linux 双架构以及原生大图感知语料仍未测量。
## 验证与相关
`tests/v2/AutoQualityTest.php` 覆盖内容适应性、档位顺序、极小/透明样本、三种格式四档实际编码与对应显式 Q 字节一致、PNG/GIF 拒绝、真实 Kernel/处理子进程与缓存身份。新增 16-bit RGBA 经 fill→rotate→grayscale→transparent pad 后，三格式四档与旧整图实体化策略输出 SHA256 完全相同；尺寸/alpha 也复核。SourceConsistencyTest 另覆盖 q_auto 双遍期间独立写进程的 ABA/原地替换。
当前完整 suite 63 tests / 877 assertions、PHPStan level 8 通过（arm64 Linux 容器内 uid 33）。q_auto 的算法是本地启发式，真实摄影/文字/透明素材的视觉校准仍未完成，见 [进度](../../progress.md)。
代码：`src/Image/AutoQuality.php`、`Pipeline.php`；基准：`bench/quality-memory.php`、`bench/quality-memory-worker.php`；[Image](README.md)、[Transformation](../Transformation/README.md)、[进度](../../progress.md)。
