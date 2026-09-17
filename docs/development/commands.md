# 常用命令
## 何时读
安装、验证、启动或查看交付配置时。在仓库根目录执行；表中未执行项不是通过证明。
## 命令
| 目的 | 命令 | 前提/本次结果 |
|---|---|---|
| 安装依赖 | `composer install` | PHP/扩展就绪；本次未执行 |
| 全部测试 | `composer test` | 2026-09-17：通过，13 tests / 44 assertions |
| 单文件测试 | `vendor/bin/phpunit tests/v2/HttpTest.php` | 同测试环境；本次未单跑 |
| 单用例筛选 | `vendor/bin/phpunit --filter testRealFillAndChain` | 同测试环境；本次未单跑 |
| 静态分析 | `composer analyse` | 当前失败：excludePaths 指向不存在的 src/EvaThumber |
| 依赖平台检查 | `composer check-platform-reqs` | 建议新环境执行；本次未执行 |
| Compose 配置检查 | `docker compose config --quiet` | 只校验配置，不证明服务健康；本次未执行 |
| 构建并启动 | `docker compose up --build` | 先处理交付/健康检查疑点；本次未执行 |
| 服务日志 | `docker compose logs --tail=100 evathumber` | 已启动的开发实例；日志可能含路径，分享前脱敏 |
| 本地开发 HTTP | `EVATHUMBER_SOURCE="$PWD/data/images" EVATHUMBER_CACHE="$PWD/data/cache" php -d ffi.enable=true -S 127.0.0.1:8081 public/index.php` | 由入口非 worker 分支推导；本次未启动，不用于生产 |
| 差异检查 | `git diff --check`、`git status --short` | 包括未追踪文档，勿自动 commit |
## 说明
composer test = phpunit；composer analyse = phpstan analyse --memory-limit=512M。`bin/transform.php` 是 stdin JSON 内部 IPC，不作为公共手工转换命令推荐。
## 相关
- 配置：`composer.json`、`phpunit.xml`、`phpstan.neon`、`compose.yaml`。
- [环境](setup.md)、[测试](testing.md)、[部署](../operations/deploy.md)。
