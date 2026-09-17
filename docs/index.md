# 工程文档索引
按任务选择最少文档，再读文档指向的代码。源码行为优先；验证状态见 [首扫与待确认](agent-handbook.md)。
| 任务 | 路径 |
|---|---|
| 产品推进、验证结果、剩余发布门槛 | [progress](progress.md) |
| 理解项目、主数据流 | [架构概览](architecture/overview.md) |
| 边界、耦合、职责归属 | [模块边界](architecture/boundaries.md) |
| 固定槽位准入历史与当前常驻池 | [准入 ADR](architecture/adr/0001-cache-admission.md)、[常驻池 ADR](architecture/adr/0002-persistent-transform-pool.md) |
| URL 路由、公有 ID、版本段 | [Url](components/Url/README.md) |
| 参数/组合规则、规范化 | [Transformation](components/Transformation/README.md) |
| 原图定位、MIME 与来源 | [Source](components/Source/README.md) |
| 变换、编码、库入口、子进程 | [Image](components/Image/README.md) |
| 缓存键、准入、容量、发布 | [Cache](components/Cache/README.md) |
| HTTP 响应、格式协商、Settings | [Http](components/Http/README.md) |
| 尺寸/字节/参数限额 | [Security](components/Security/README.md) |
| 错误类型与跨层传播 | [Exception](components/Exception/README.md) |
| 准备环境/依赖 | [setup](development/setup.md) |
| 执行命令 | [commands](development/commands.md) |
| 测试惯例/覆盖/CI | [testing](development/testing.md) |
| Docker/Compose 交付 | [deploy](operations/deploy.md) |
| 环境变量/资源配置 | [config](operations/config.md) |
| 运行特征/排障 | [runtime](operations/runtime.md) |
| 首扫证据、冲突、待确认 | [agent-handbook](agent-handbook.md) |
| v1 历史迁移 | [migration-v1](migration-v1.md) |
| 新写/同步 docs | [spec](spec.md) |
重要决策记录于 `architecture/adr/`，不为普通改动补造 ADR。旧 `architecture.md` 仅保留导航。
