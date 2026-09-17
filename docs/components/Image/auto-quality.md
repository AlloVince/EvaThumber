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
- 为避免 sequential loader 在分析后编码时回读失败，Pipeline 对已受输出尺寸限制的图像 copyMemory 一次；分析样本再实体化。输出缓冲额外成本随像素、bands 和位深增长，256 样本上限不等于整个任务内存上限。HTTP 仍有处理子进程截止时间；库调用无硬内存/时间隔离。
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
## 验证与相关
`tests/v2/AutoQualityTest.php` 覆盖内容适应性、档位顺序、极小/透明样本、三种格式四档实际编码与对应显式 Q 字节一致、PNG/GIF 拒绝、真实 Kernel/处理子进程与缓存身份。
代码：`src/Image/AutoQuality.php`、`Pipeline.php`；[Image](README.md)、[Transformation](../Transformation/README.md)、[进度](../../progress.md)。
