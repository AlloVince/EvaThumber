# 常用命令
## 何时读
安装、验证、启动或查看交付配置时。在仓库根目录执行；表中未执行项不是通过证明。
## 命令
| 目的 | 命令 | 前提/本次结果 |
|---|---|---|
| 安装依赖 | `composer install` | PHP/扩展就绪；本次未执行 |
| 全部测试 | `composer test` | 当前完整 suite 通过，77 tests / 949 assertions（arm64 容器内 uid 33）；见 progress |
| 单文件测试 | `vendor/bin/phpunit tests/v2/HttpTest.php` | 同测试环境；本次未单跑 |
| 单用例筛选 | `vendor/bin/phpunit --filter testRealFillAndChain` | 同测试环境；本次未单跑 |
| 静态分析 | `composer analyse` | 当前通过；已移除不存在路径的 excludePaths |
| 依赖平台检查 | `composer check-platform-reqs` | 本机已通过；新环境仍需执行 |
| Compose 配置检查 | `docker compose config --quiet` | 已通过；只校验配置，不证明服务健康 |
| 构建并启动 | `docker compose up --build` | 已用独立项目实跑：Healthy、真实变换、重启后命名卷 HIT；tmpfs 已加 uid/gid=33 |
| Compose 准入验收 | `php tests/compose-admission.php CONTAINER http://127.0.0.1:3999` | 需先独立项目 up --wait；满槽 HIT/503/释放恢复已通过 |
| 服务日志 | `docker compose logs --tail=100 evathumber` | 已启动的开发实例；日志可能含路径，分享前脱敏 |
| 本地开发 HTTP | `EVATHUMBER_SOURCE="$PWD/data/images" EVATHUMBER_CACHE="$PWD/data/cache" php -d ffi.enable=true -S 127.0.0.1:8080 public/index.php` | 由入口非 worker 分支推导；本次未启动，不用于生产 |
| 差异检查 | `git diff --check`、`git status --short` | 包括未追踪文档，勿自动 commit |
## 容器验收
`docker build --platform linux/arm64 --target production -t evathumber:verify-arm64 .` 后依次运行：

```bash
php tests/container-smoke.php   evathumber:verify-arm64 linux/arm64
php tests/rc1-acceptance.php   evathumber:verify-arm64 linux/arm64
php tests/product-acceptance.php evathumber:verify-arm64 linux/arm64
php tests/docker-acceptance.php  evathumber:verify-arm64 linux/arm64
php tests/crash-recovery.php   evathumber:verify-arm64 linux/arm64
```

四套验收脚本只用 README 公开的 `docker run` 参数，不需要缓存卷或调优环境变量。amd64 替换平台与标签；本地 amd64 仅为 QEMU 模拟，原生 gate 是 CI。development 目标同样构建后，容器内以 uid 33 执行 `composer test -- --do-not-cache-result && composer analyse`。这些脚本需要宿主机 Composer 依赖与 Docker，不在应用容器内运行。

真实 HTTP 压测报告：`php bench/http-load.php evathumber:verify-arm64 pool`（参数见脚本头），归档在 `bench/results/`。破坏恢复套件同时把 JSON 报告写入 `bench/results/rc1-crash-recovery/`。
## 说明
composer test = phpunit；composer analyse = phpstan analyse --memory-limit=512M。`bin/transform.php` 是 stdin JSON 内部 IPC，不作为公共手工转换命令推荐。
## 相关
- 配置：`composer.json`、`phpunit.xml`、`phpstan.neon`、`compose.yaml`。
- [环境](setup.md)、[测试](testing.md)、[部署](../operations/deploy.md)。
