# 首扫证据与待确认
## 何时读
确认知识来源、旧文档差异或选择后续工作时；日常导航使用 [index](index.md)，不要重复整仓首扫。
本文记录接入时的历史基线，不代表当前验收；后续产品实现与验证见 [progress](progress.md)。PHPStan 排除路径已修复，容量淘汰、文件投递租约及 URL 兼容增量已更新对应模块文档。
## 接入基线
- 2026-09-17，用户确认 B 已有项目 + Standard + defaults。初始 Git 工作区干净；未发现已有 AGENTS/.ai 规则。
- 协议来源：[agent.protocol](https://github.com/AlloVince/agent.protocol/tree/945e6d7340f8e31861014127bf6aaa70d77596e4)，版本 0.2，固定提交 `945e6d7340f8e31861014127bf6aaa70d77596e4`。已读目录树、AGENTS.template、memory.template、defaults、四份 workflow、docs/spec。
- defaults/workflow 与 docs/spec 按原文复制；AGENTS/memory 按模板生成终稿。业务事实只进入 docs，未引入 skills、ai-profile、PROJECT_HISTORY 或空模块/ADR。
- 扫描：八个 src 模块、public/bin 入口、双语 README、原有 docs、Composer 清单/本地锁版本、三个测试文件、测试/分析/CI/部署与忽略配置。未读取密钥、资源大文件或 vendor 实现。
## 模块与事实来源
| 对应源码目录 | 主要证据与知识页 |
|---|---|
| src/Url | Parser/ImageUrl → [Url](components/Url/README.md) |
| src/Transformation | Parser/ParameterRules/Step/Transformation → [Transformation](components/Transformation/README.md) |
| src/Source | LocalSource/SourceInterface/SourceImage → [Source](components/Source/README.md) |
| src/Image | Pipeline/Thumber/IsolatedProcessor → [Image](components/Image/README.md) |
| src/Cache | DiskCache/CacheEntry → [Cache](components/Cache/README.md) |
| src/Http | Kernel/Settings/FormatNegotiator → [Http](components/Http/README.md) |
| src/Security | Limits → [Security](components/Security/README.md) |
| src/Exception | ImageException → [Exception](components/Exception/README.md) |
## 旧知识迁移与冲突
| 原知识 | 处理/代码事实 |
|---|---|
| docs/architecture.md | 拆入 architecture 与八模块文档；旧路径保留导航。缓存实际为全目录共享准入锁，不是 lock stripes |
| 旧架构中的 encoder version 缓存身份 | Kernel 只有策略字符串，未包含实际 libvips/encoder 版本，见 Cache/Http |
| README “所有处理在子进程” | 只限 HTTP miss；Thumber/Pipeline 直接库调用不隔离 |
| README “不支持均 400” | 动画/格式 415、方法 405 等，按 runtime 错误表；README 原文保留，差异在此标明 |
| README “仅缓存可写” | Compose 还有 /tmp、/config/caddy、/data/caddy 的 tmpfs |
| README 生产级/双架构/Planned | 视为未验收陈述；CI 配置不等于运行证明，路线图待维护者确认 |
| docs/migration-v1.md | 保留历史映射，标注 Planned 非承诺、根 index 与 public/index 的区别；未重新审计 v1 历史代码 |
| 外部旧会话记忆 | URL/缓存/HTTP 未完成、v1 目录仍在等陈述已过时，不复制成待实现清单；稳定知识以当前 docs 为准 |
## 接入时确认问题（当时未修复）
1. `.gitignore` 忽略 composer.lock；`bin/.gitignore` 的 `*` 忽略 transform.php。两者本地存在但未跟踪，干净检出缺少 Docker/HTTP 必需输入；交付策略待确认。
2. compose.yaml 健康检查为 8080，Caddy/镜像检查/容器端口为 8081；实际容器健康未验证。
3. `composer analyse` 退出 1：phpstan.neon 的 excludePaths 引用不存在的 src/EvaThumber。历史“level 8 零错误”不作为当前结果。
4. CI matrix.platform 未用于切换 test 架构；test job 未显式安装 libvips。实际 runner 依赖与远程执行结果待验证。
5. `.dockerignore` 白名单不含测试/工具配置，development 镜像不是完整开发环境。
## 接入时验证与后续建议
- 本地 `composer test` 与直接 `vendor/bin/phpunit tests/v2` 均通过：13 tests / 44 assertions，PHP 8.5.10；具体覆盖见 [testing](development/testing.md)。
- 静态分析如上失败；没有修改配置、业务代码或依赖来消除现有问题。未执行 Docker 构建、部署、远程 CI、性能/安全审计。
- 建议由维护者选定独立后续任务：先确认交付文件追踪策略与分析旧路径，再验收 CI/容器健康；缓存清理、生产 TLS/备份/回滚及 README 路线图需另行决策，不自动扩展本次范围。
## 相关
- [架构](architecture/overview.md)、[开发验证](development/testing.md)、[部署](operations/deploy.md)、[历史迁移](migration-v1.md)。
