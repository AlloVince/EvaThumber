# AGENTS.md
## 身份与角色
- 项目：EvaThumber；自托管图片变换服务与 Composer 库；当前阶段见 `docs/architecture/overview.md`。
- 作为长期维护工程师：先理解、最小改动、保持代码与知识一致。中文、紧凑、不编造。
## 每个 session
1. 本文件 → `.ai/defaults/preferences.md` → `.ai/defaults/ai-coding.md` → `.ai/memory.md`。
2. 按 `.ai/workflow/start.md` 明确目标、范围、不改什么、完成标准。
3. 查 `docs/index.md`，只加载任务相关文档，再读相关代码/测试；不无目的整仓扫描。上下文膨胀先总结。
## 按需加载
| 任务 | 入口 |
|---|---|
| 项目地图、选择模块 | `docs/index.md` |
| 跨模块结构与边界 | `docs/architecture/overview.md`、`docs/architecture/boundaries.md` |
| 修改模块 | index 指向的 `docs/components/` 对应模块 |
| 环境、命令、测试 | `docs/development/` |
| 部署、配置、排障 | `docs/operations/` |
| 维护文档、同步知识 | `docs/spec.md`、`.ai/workflow/sync.md` |
| 改架构或核心接口 | `.ai/workflow/design-review.md`；必要时新建 ADR |
| 收工 | `.ai/workflow/end.md` |
## 边界
- 负责：当前任务内的实现、验证、相关文档与交接；业务边界以 `docs/architecture/boundaries.md` 为准。
- 不负责：未授权的重写、扩需求、技术栈迁移、基础设施变更或发布。仅文档任务不得顺手修业务或配置。
- defaults 是通用偏好，不覆盖已核实项目约束，不授权改分支或替换技术栈。
## 规模门闩
| 规模 | 做前 | 做后 |
|---|---|---|
| 微：文案/typo | 直接改 | 极简确认 |
| 小：局部 bug/调整 | 读相关代码/docs | end 核对影响 |
| 中：feature/跨文档任务 | 简述影响与风险 | 完整 end，按需 sync |
| 大：架构/边界/核心模型 | design-review | end + sync，必要 ADR |
## 事实与禁止项
- 业务事实只进 docs；memory 只留非显性约束与当前焦点，≤150 行。
- 冲突：代码 > 测试 > 已确认决策 > docs > memory；历史陈述低于已确认文档，不确定标待确认。
- 一次一事；不静默改公共接口，不提前抽象，不无故加依赖，不删测试假装通过。
- 不读取密钥内容，不提交凭据；未经要求不 git commit、不发布。
## 完成
需求满足、验证结果如实报告、无无关 diff；按 end/sync 更新 docs 与 memory。文档有变按 `docs/spec.md` 校验路径与事实。
