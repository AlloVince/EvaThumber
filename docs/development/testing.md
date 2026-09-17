# 测试与 CI
## 何时读
改行为、加回归、判断验证证据或排查 CI 时。
## 运行与惯例
PHPUnit 12，vendor/autoload.php 引导；tests/v2 为唯一 suite，warning/risky 视为失败。数据提供器用 `#[DataProvider]` 属性，不使用旧 doc-comment 注解。
测试用 libvips 实时生成小图片、随机临时目录，并在 finally/tearDown 清理；不要引入生产原图。macOS 路径断言使用 realpath。无 alpha 的三波段图不能假定可直接 flatten。
## 已有覆盖
| 文件 | 实际覆盖 |
|---|---|
| `tests/v2/PipelineTest.php` | f 覆盖、投递格式默认值、4 组不支持参数、fill 像素/尺寸、fit 后旋转；末尾 q/f 分段等价、直接模型构建、重复键与后续像素操作拒绝 |
| `tests/v2/StorageAndUrlTest.php` | cloud/version/chain/嵌套及歧义边界 URL、来源 MIME 与歧义、缓存命中绕过 producer、容量失败原子性与临时清理 |
| `tests/v2/CacheLifecycleTest.php` | 最老写入淘汰、生产失败保留旧条目、跳过被租用条目 |
| `tests/v2/CacheConcurrencyTest.php` | 跨 PHP 进程同键 miss 复用发布产物、忙等 250ms 截止后 503、命中绕过忙锁、强杀 producer 后锁释放与孤儿临时清理；7 占用槽位加真实 producer 填满 8 槽、溢出立即 503、命中绕过满槽、SIGKILL 后全部槽位恢复 |
| `tests/v2/HttpTest.php` | 直接 Kernel 调用：真实子进程变换、f_auto WebP、Vary、MISS/HIT、ETag 304、非法变换、healthz；发送租约与回收、版本隔离、analytics 白名单与 URI 限长、投递分段共享缓存、忙准入 503 Retry-After 后恢复 |
| `tests/v2/AutoQualityTest.php` | 内容/格式自适应 Q、四档顺序、极小/透明图、三格式四档编码与显式 Q 字节一致、PNG/GIF 拒绝、HTTP 缓存身份 |
新增 `tests/v2/ProcessorFailureTest.php`：真实 Symfony Process 的超时 504、异常退出 422、结构化拒绝 413，均验证部分文件不发布、临时清理及下一次真实变换恢复。`HttpTest` 另验证编码空格/加号在单条目淘汰后的身份与 ETag。
当前完整 suite（`vendor/bin/phpunit`）：PHP 8.5.10，宿主机及 arm64/amd64 开发容器均 **35 tests / 387 assertions，通过**。不等于生产网络/FrankenPHP/所有格式覆盖；详细证据及本地执行模型实验见 [进度](../progress.md)。
## 静态分析与缺口
`composer analyse`：**通过**；已移除 phpstan.neon 中不存在的排除路径，未加忽略规则或降低等级。
尚未完整覆盖所有 resize/codec、动画拒绝、全部 Limits 边界、Accept 各 q/通配符组合、方法限制、来源并发变更和处理中停止。HEAD/304 已由独立容器 HTTP 验收覆盖，跨进程准入与处理超时已有回归；满槽准入另经 `tests/compose-admission.php` 在真实 Compose HTTP 入口验证。新增行为时按影响选择测试，不冒称完整覆盖。
## CI 当前配置
`.github/workflows/ci.yml` 在 master/main push、v* tag、PR 触发；test job 将 linux/amd64 对应 ubuntu-24.04、linux/arm64 对应 ubuntu-24.04-arm。每个 runner 构建 development 镜像（Dockerfile 安装 libvips），容器内运行完整测试与 PHPStan，再构建 production 镜像并由宿主 PHP 运行 `tests/container-smoke.php`。
image job 依赖 test，QEMU/buildx 构建 linux/amd64、linux/arm64；仅 v* tag 登录并推送 GHCR。仅本地相应命令验收，未触发远程工作流或发布，未读取 secret 值。容器原生库输出 heifload nclx 警告，测试通过不代表无警告。
## 相关
- 配置：`phpunit.xml`、`phpstan.neon`、`.github/workflows/ci.yml`。
- [命令](commands.md)、[首扫待确认](../agent-handbook.md)。
