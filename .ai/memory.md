# Project Memory
全文限高 ≤150 行；超限先删 Assumed、过时或已升格 docs 的条目。
只记代码/docs 难表达且影响后续协作的信息；不写业务说明、架构复述或流水账。
置信：Confirmed（代码/测试/人确认）｜Assumed（待验证，用完删除或升格）。
更新：2026-09-17
## 当前焦点
- Confirmed：用户授权持续推进常驻变换池的实现与完整验收；当前证据/缺口以 `docs/progress.md` 顶部和 ADR 0002 为准，下方与 agent-handbook 是历史基线。不能将局部 IPC/HTTP 回归通过当成整体验收，不能自造用户要求缩小范围；未授权 commit 或发布。
## 雷区与禁忌
- Confirmed：外部旧会话记忆可能停留在中间状态；本仓 docs 与当前代码优先，不据旧任务清单重新实现已有模块。
## 调试手册
- 暂无非显性条目；稳定排障事实见 `docs/operations/runtime.md`。
## 待验证
- 无额外 Assumed 条目；首扫疑点集中在 `docs/agent-handbook.md`，不在此重复。
## 协作偏好（项目级）
- Confirmed：采用 Standard + defaults；普通偏好/流程保持上游原文，项目特例写 docs。接入只建知识体系，不顺手修缺陷。
