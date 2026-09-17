# Http
## 何时读
修改请求编排、响应头、格式协商、配置装配时。
## 职责与入口
`Kernel(Settings)` 实现 HttpKernelInterface；`handle(Request, type=MAIN_REQUEST, catch=true): Response`。`Settings::fromEnvironment()` 是环境配置入口；`FormatNegotiator::negotiate(string): string` 解析 Accept。
- 方法先限 GET/HEAD，否则 405 + Allow。`/healthz` 返回 ok/version、no-store，先于查询串检查，且不测试磁盘/codec 可用性。
- 图片请求拒绝查询串；解析 URL/来源后决定格式，调用 DiskCache + IsolatedProcessor。
- HTTP 缓存身份为 JSON：`evathumber-2-policy-1`、source identity、canonical、format、Limits；不含 cloud/version 或实际 libvips 版本。
- 成功 BinaryFileResponse 携带 Content-Type、nosniff、HIT/MISS、public/max-age、ETag、Last-Modified；f_auto 加 Vary: Accept。isNotModified/prepare 处理条件请求和 HEAD。
## 协商
候选依次 WebP、AVIF、JPEG、PNG；同质量优先先列格式。WebP/AVIF 必须显式接受，通配符只可选择 JPEG/PNG；具体 MIME q 优先于通配符；没有正质量候选返回 406。不协商 GIF。
## 错误与边界
ImageException 转 error/message JSON + no-store，503 加 Retry-After: 1；其他 Throwable 默认记日志并返回通用 500，catch=false 时重抛未知错误。
Settings 在请求外装配，初始化失败不由 Kernel 捕获。Http 不做像素处理，不提供认证/签名/限流或租户隔离。虽然声明 Symfony Routing/EventDispatcher 依赖，当前没有使用相关路由/事件对象。
## 依赖与相关
- 依赖：Url、Source、Cache、Image、Limits、ImageException，Symfony HttpFoundation/HttpKernel。
- 代码：`src/Http/Kernel.php`、`src/Http/Settings.php`、`src/Http/FormatNegotiator.php`、`public/index.php`。
- 测试：`tests/v2/HttpTest.php`（直接调用 Kernel，非真实网络/FrankenPHP 测试）。
- [配置](../../operations/config.md)、[主流程](../../architecture/overview.md)。
验证于：2026-09-17。
