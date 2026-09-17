# 同步

何时：人改了代码、AI 改了代码、外部合并、怀疑文档过期。微改动且无行为变化可跳过。

## 1. 先看变化
git diff / 近期提交 / 改动文件。先理解再改文档。

## 2. 文档影响
| 影响 | 更新 |
|---|---|
| 边界/数据流/结构 | `docs/architecture/`，必要时 ADR |
| 模块职责/接口/行为 | `docs/components/<module>/` |
| 命令/环境/测试方式 | `docs/development/` |
| 部署/配置/运行 | `docs/operations/` |
| 加载路径变化 | `docs/index.md`、必要时 AGENTS 加载表 |

## 3. Memory
仅写入影响未来开发、且代码/docs 看不出来的信息。见 memory 文首规则。稳定事实应升格进 docs 并删除 memory 对应条。

## 4. 置信与清理
条目标记 Confirmed / Assumed。删除过时、矛盾内容。冲突时：代码 > 测试 > 决策 > docs > memory。

## 5. 报告
代码变化 / 文档更新 / memory 更新 / 残留疑点。
