# 测试与 CI
## 何时读
改行为、加回归、判断验证证据或排查 CI 时。
## 运行与惯例
PHPUnit 12，vendor/autoload.php 引导；tests/v2 为唯一 suite，warning/risky 视为失败。数据提供器用 `#[DataProvider]` 属性，不使用旧 doc-comment 注解。
测试用 libvips 实时生成小图片、随机临时目录，并在 finally/tearDown 清理；不要引入生产原图。macOS 路径断言使用 realpath。无 alpha 的三波段图不能假定可直接 flatten。
## 已有覆盖
| 文件 | 实际覆盖 |
|---|---|
| `tests/v2/PipelineTest.php` | f 覆盖、投递格式默认值、4 组不支持参数、fill 像素/尺寸、fit 后旋转 |
| `tests/v2/StorageAndUrlTest.php` | cloud/version/chain URL、来源 MIME 与歧义、缓存命中绕过 producer、容量失败原子性与临时清理 |
| `tests/v2/HttpTest.php` | 直接 Kernel 调用：真实子进程变换、f_auto WebP、Vary、MISS/HIT、ETag 304、非法变换、healthz |
2026-09-17 `composer test`：PHP 8.5.10，**13 tests / 44 assertions，通过**。不等于生产网络/FrankenPHP/所有格式覆盖。
## 静态分析与缺口
`composer analyse`：**退出 1**，phpstan.neon 排除不存在的 `src/EvaThumber`；本次只记录，不修改配置或绕过检查。
尚无专门用例覆盖所有 resize/codec、动画拒绝、Limits 边界、并发准入、处理超时、Accept 各 q/通配符组合、HEAD/方法/查询串、来源并发变更。新增行为时按影响选择测试，不冒称完整覆盖。
## CI 当前配置
`.github/workflows/ci.yml` 在 master/main push、v* tag、PR 触发；test job 用 setup-php 8.5、composer install/test/analyse。matrix.platform 为 amd64/arm64，但未在 runner 或步骤中使用，不能称为双架构测试；未显式安装 libvips 系统库，实际 runner 可用性待验证。
image job 依赖 test，QEMU/buildx 构建 linux/amd64、linux/arm64；仅 v* tag 登录并推送 GHCR。Git 忽略的构建输入、静态分析旧路径均是交付风险。未读取 secret 值，未触发远程工作流。
## 相关
- 配置：`phpunit.xml`、`phpstan.neon`、`.github/workflows/ci.yml`。
- [命令](commands.md)、[首扫待确认](../agent-handbook.md)。
