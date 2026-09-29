# Image
## 何时读
修改像素流水线、输出编码、库 facade 或隔离进程时。
## 职责与接口
- `Thumber::transform(SourceImage, Transformation, destination, ?format=null): void`：默认以源格式调用 Pipeline。
- `Pipeline::write(SourceImage, Transformation, destination, format): void`：重验变换与源字节限额；显式 f（非 auto）覆盖入参格式；显式 raster loader 以 `access=random` 加载、检查源尺寸与 n-pages、autorot、转 sRGB、逐步骤处理、检查输出尺寸、编码落盘。
- `IsolatedProcessor::write(publicId, Transformation, destination, format, ?identity=null): void`：使用 Http\Settings 与 Symfony Process 调 PHP CLI，stdin JSON 传任务，执行超时转换成 504。
- `bin/transform.php`：可信内部 IPC 接收端，再解析 Limits、LocalSource、Transformation 后调用 Pipeline；领域错误输出 status/error JSON 并退出 1。
- `PoolProcessor::write(publicId, Transformation, destination, format, identity): void`：私有 Unix socket 客户端；有界帧、部分读写及单调总截止。生产 Docker 默认调用常驻池；`bin/pool.php` 监督 `bin/pool-worker.php`，处理 staging 与回收，详见 [常驻池 ADR](../../architecture/adr/0002-persistent-transform-pool.md)。
## 行为
resize 支持 scale/fit/fill/crop/thumb/pad/limit；中间缩放尺寸也受输出限额。静态五格式；多页图 415，不静默首帧。a 执行旋转/翻转；e 保留 alpha 后做灰度/反色。
编码 strip 元数据；q 默认 80，JPEG/WebP/AVIF 使用 Q，PNG/GIF 不使用整数 q。q_auto[:best|good|eco|low] 使用本地内容/格式自适应启发式；PNG/GIF 自动质量明确拒绝。JPEG 有 alpha 时铺白。libvips 全局 operation cache 设为 0、concurrency 设为 2。
`AutoQuality::select()` 分析最多 256 长边样本；Pipeline 自动质量路径先实体化受输出限额约束的图像再重建图，避免重复消费同一图。策略、内存代价、测量与非等价边界见 [自动质量](auto-quality.md)。
## 边界与依赖
Pipeline/Thumber 依赖 SourceImage、Transformation、Limits、ImageException、jcupitt/vips；不依赖 HTTP。IsolatedProcessor 额外依赖 Http\Settings 和 Symfony Process。整个 Image 目录并非完全 HTTP 无依赖。
库直接调用不提供超时隔离、磁盘缓存或 Accept 协商；f_auto 在 Pipeline 不自行选择格式，使用已传入的格式。
## 雷区
**源必须以 `access=random` 加载。** `vips_rot`/`vips_flip` 是 in-place 操作，跑在 `access=sequential` 的源上、且原图超过约 1 MPix 时 libvips 会以 `VipsJpeg: out of order read` 中止，被 `Pipeline::write` 的 catch 压成通用 422。实测在本仓 1200×800 fixture 上只有 90°/270° 旋转会触发，`a_180`/`a_hflip`/`a_vflip` 仍然通过——**不能用后者判断这条路径健康**。`copy()` 救不回来（实测前后都无效），必须改 loader 契约；代价是峰值 RSS 最多高约 14 MiB（28 MPix 源实测 85 MiB，仍远低于 192 MiB 的 WORKER_RSS_MIB）。
只有 alpha 才调用 flatten；三波段 Image::black 不自带 alpha。Config 调用及库执行会影响进程级 libvips 设置。不要将可信 IPC 当成公开用户接口；输出目标由调用方负责。
`bin/transform.php` 对 ImageException 写 STDERR：响应体保持通用措辞不泄露内部，若无这行日志，libvips 失败原因不会出现在任何日志里。
## 相关
- 代码：`src/Image/Pipeline.php`、`src/Image/Thumber.php`、`src/Image/IsolatedProcessor.php`、`bin/transform.php`。
- 测试：`tests/v2/PipelineTest.php`、`tests/v2/HttpTest.php`、`tests/v2/ProcessorFailureTest.php`（真实子进程超时、异常退出、结构化拒绝后的缓存恢复）。
- [运行特征](../../operations/runtime.md)、[限额](../Security/README.md)。
验证于：2026-09-17。
