# Session 启动

目标：用最小上下文恢复到可开工状态。

## 1. 固定加载
1. `AGENTS.md`
2. `.ai/defaults/preferences.md`
3. `.ai/defaults/ai-coding.md`
4. `.ai/memory.md`（限高；若超限先裁剪/总结再继续）

## 2. 理解任务
用四句话内搞清：目标 / 范围 / 不改什么 / 完成标准。

## 3. 定规模
微 | 小 | 中 | 大（见 AGENTS 分级）。大 → 先读 `design-review.md`。

## 4. 按需加载
- 查 `docs/index.md` 或 AGENTS 加载表
- 只读相关 `docs/components/<module>/`
- 跨模块才读 `docs/architecture/`
- 跑通/测试读 `docs/development/`
- 部署运维读 `docs/operations/`
然后读相关代码与测试。禁止整仓扫描。

## 5. Context Budget
优先：最小文档集 → 组件 docs → 再代码。上下文膨胀则先总结。

## 6. 确认（按规模）
- 微/小：可直接干
- 中：三五行说明影响面、做法、风险
- 大：完成 design-review 清单；需求不清或多方案时等人确认

## 7. 开工
最小改动；遵循现有约定与 defaults；不碰无关区域。
