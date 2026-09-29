# 架构概览
## 何时读
首次定位项目、跨模块变更、确认库与服务的运行差异。
## 系统与阶段
EvaThumber 2 是 PHP 图片变换 Composer 库及自托管 HTTP 服务，接受 Cloudinary 风格 URL 的有限子集，原图来自本地目录，处理引擎为 libvips。不是 Cloudinary 全量替代品。
`composer.json` 要求 PHP `~8.5.0`、FFI/fileinfo、jcupitt/vips `^2.5` 与直接依赖的 Symfony `7.4.*`；BSD-3-Clause。健康接口回报 `src/Version.php` 里 `Version::VERSION` 声明的版本号（当前 `2.0.1`），不能据 README 推定已经生产验收。
## 主数据流
1. `public/index.php` 从环境创建 `Settings` 和复用的 `Kernel`；每次请求独立创建 Request/Response。
2. `Kernel::handle()` 先限定 GET/HEAD；`/healthz` 直接返回。图片请求限制完整 URI 长度，仅接受并忽略标量 `_a`/`_i` analytics 查询参数。
3. `Url\Parser` 解析原始路径并调用 `Transformation\Parser`，产出 `ImageUrl` 和规范化变换。
4. `LocalSource::resolve(publicId)` 定位唯一原图，检查路径、字节数、MIME，生成来源修订身份。
5. HTTP 决定最终格式：显式 `f` > 投递扩展名 > 原格式；`f_auto` 由 Accept 协商。
6. `Kernel` 组合策略版本、来源身份、URL version、canonical、最终格式、Limits 作为缓存身份；`DiskCache` 再哈希为文件名。version 不指向历史快照，不设置 immutable。
7. 命中绕过缓存准入与发布锁，但源解析仍读取文件摘要。miss 最多 8 个已准入请求（含同键等待者），256 个 generation lock stripes 使同键复用、不同条带并行；全局发布锁只覆盖短临界区。HTTP 同键等待预算为 TIMEOUT + 队列等待 + 3 秒，发布锁每次最多 250ms。
8. 生产 `PoolProcessor` 经私有 Unix socket 调有界常驻池，worker 构建 Pipeline 写私有 staging，supervisor 核对目标 inode 并复制到 HTTP 临时文件，缓存检查容量后 rename。未配置 socket 的显式隔离模式仍使用 `bin/transform.php`。配置池失败不回退。来源在处理前后复核，但尚不是不可变快照。见 [常驻池 ADR](adr/0002-persistent-transform-pool.md)。
9. `CachedFileResponse` 继承 BinaryFileResponse，持有共享文件租约至正文发送完成或销毁，避免投递期间被淘汰；处理缓存头与条件请求。领域异常转 JSON，未知异常记日志并通常返回 500。
## 两种使用方式
- 服务：HTTP 编排具备磁盘缓存、格式协商与处理超时隔离。
- 库：`Thumber::transform()` → `Pipeline::write()`，调用方提供 SourceImage、Transformation、输出路径；**不自动获得 HTTP 缓存、Accept 协商或子进程截止时间**。
## 结构与运行
- 八个模块对应八个 `src/` 子目录，详见 [边界](boundaries.md)。没有数据库、远程源或分布式队列；只有 supervisor 内存中的本地有界等待队列。
- FrankenPHP 配置 16 个 HTTP worker；PHP 入口每个最多处理 500 次请求。变换默认由 2 个常驻 PHP CLI worker 执行；`bin/serve.php` 负责 HTTP→pool 顺序停机。
- 缓存容量不足时按最老写入时间淘汰未被租用条目；无磁盘 TTL。来源修订变化生成新键。
- `bench/` 用于真实 HTTP 压测与本地作业开销对照，不是生产 worker；报告与剩余门槛见 [进度](../progress.md)。
- 当前实现直接使用 HttpKernelInterface、HttpFoundation、Process；虽然声明了 Routing/EventDispatcher 依赖，Kernel 没有使用路由表或事件分发流程。
## 相关
- 代码：`public/index.php`、`src/Http/Kernel.php`、`src/Image/`、`bin/transform.php`。
- [部署](../operations/deploy.md)、[已知差异](../agent-handbook.md)。
验证于：2026-09-17；当前 arm64 生产 HTTP 已有增量验证，整体发布门槛仍未通过，见 progress。
