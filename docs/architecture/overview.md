# 架构概览
## 何时读
首次定位项目、跨模块变更、确认库与服务的运行差异。
## 系统与阶段
EvaThumber 2 是 PHP 图片变换 Composer 库及自托管 HTTP 服务，接受 Cloudinary 风格 URL 的有限子集，原图来自本地目录，处理引擎为 libvips。不是 Cloudinary 全量替代品。
`composer.json` 要求 PHP `~8.5.0`、FFI/fileinfo、jcupitt/vips `^2.5` 与直接依赖的 Symfony `7.4.*`；BSD-3-Clause。健康接口标记 `2.0.0-dev`，不能据 README 推定已经生产验收。
## 主数据流
1. `public/index.php` 从环境创建 `Settings` 和复用的 `Kernel`；每次请求独立创建 Request/Response。
2. `Kernel::handle()` 先限定 GET/HEAD；`/healthz` 直接返回。图片请求拒绝查询字符串。
3. `Url\Parser` 解析原始路径并调用 `Transformation\Parser`，产出 `ImageUrl` 和规范化变换。
4. `LocalSource::resolve(publicId)` 定位唯一原图，检查路径、字节数、MIME，生成来源修订身份。
5. HTTP 决定最终格式：显式 `f` > 投递扩展名 > 原格式；`f_auto` 由 Accept 协商。
6. `Kernel` 组合策略版本、来源身份、canonical、最终格式、Limits 作为缓存身份；`DiskCache` 再哈希为文件名。
7. 命中直接复用文件，不运行处理子进程或 libvips；未命中在缓存目录共享的非阻塞锁内调用 `IsolatedProcessor`。
8. 子进程 `bin/transform.php` 经 stdin JSON 接收作业，再解析来源和变换，`Pipeline` 构建 libvips 惰性图并写临时文件；缓存检查容量后 rename 原子发布。
9. `BinaryFileResponse` 输出文件与缓存头，处理条件请求；领域异常转 JSON，未知异常记日志并通常返回 500。
## 两种使用方式
- 服务：HTTP 编排具备磁盘缓存、格式协商与处理超时隔离。
- 库：`Thumber::transform()` → `Pipeline::write()`，调用方提供 SourceImage、Transformation、输出路径；**不自动获得 HTTP 缓存、Accept 协商或子进程截止时间**。
## 结构与运行
- 八个模块对应八个 `src/` 子目录，详见 [边界](boundaries.md)。没有数据库、远程源实现或队列。
- FrankenPHP 配置两个 worker；PHP 入口每个 worker 最多处理 500 次请求。图像原生处理发生在独立 CLI 进程。
- 缓存只限制容量，不自动淘汰旧条目；来源修订变化生成新键。
- 当前实现直接使用 HttpKernelInterface、HttpFoundation、Process；虽然声明了 Routing/EventDispatcher 依赖，Kernel 没有使用路由表或事件分发流程。
## 相关
- 代码：`public/index.php`、`src/Http/Kernel.php`、`src/Image/`、`bin/transform.php`。
- [部署](../operations/deploy.md)、[已知差异](../agent-handbook.md)。
验证于：2026-09-17；源码首扫与本地测试，未验证容器生产运行。
