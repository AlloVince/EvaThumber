# 开发环境
## 何时读
新机器准备、依赖问题、选择本地或容器运行时。
## 环境与安装
- PHP `~8.5.0`（8.5.x），Composer；FFI、fileinfo。PHP CLI 需允许 FFI；开发测试还需 PHPUnit 要求的扩展（以 Composer 平台检查为准）。
- 系统安装 libvips 共享库及所需 raster codecs；Composer 的 jcupitt/vips 不包含系统 libvips。macOS 可用 Homebrew 的 php/composer/vips，版本需满足约束；本次没有安装依赖。
- 从根目录执行 `composer install`；**先确认交付缺口**：本地 composer.lock 与 bin/transform.php 被忽略，干净检出与当前工作区不等价。见 [待确认](../agent-handbook.md)。
- 原图目录需存在且可读；缓存目录需可创建/写入。测试自行生成临时图，不依赖 upload 或大图片资源。
- Docker 路径见 [deploy](../operations/deploy.md)；不要据现有 Dockerfile 推定构建验收已完成。
## 当前核验环境（2026-09-17）
macOS；PHP 8.5.10、Composer 2.10.3、libvips 8.18.6。本地 lock：jcupitt/vips 2.6.1、PHPUnit 12.5.35、PHPStan 2.2.14；直接 Symfony HTTP/Routing/Process 依赖为 7.4.x，部分传递包为 8.1.x，不能说全部 Symfony 包锁在 7.4。
## 既有约定
PSR-4 `EvaThumber\\` → src，strict_types=1、显式类型、final/readonly 小类；PHPDoc 描述集合形状。测试命名空间映射到 tests。无单独 formatter 配置；PHPStan level 8。
当前分支为 master，CI 接受 master/main；defaults 偏好 main 不授权重命名。默认 Node/Python 工具偏好不适用于替换本项目 PHP 栈。
## 相关
- 代码/配置：`composer.json`、`composer.lock`（本地）、`phpunit.xml`、`phpstan.neon`。
- [命令](commands.md)、[测试](testing.md)。
