# docs 规范

给 bootstrap 与日常维护用。docs = 全部项目事实；可验证；按需加载。

## 目录
```
docs/
├── index.md
├── architecture/
│   ├── overview.md
│   ├── boundaries.md
│   └── adr/           # 有决策再建
├── components/
│   └── <module>/      # 与代码模块对齐；改谁读谁
├── development/
│   ├── setup.md
│   ├── commands.md
│   └── testing.md
└── operations/
    ├── deploy.md
    ├── config.md
    └── runtime.md
```

## Profile 裁剪
- **minimal**：`development/commands.md` + 极简 `architecture/overview.md`；可无 components/operations
- **standard**：上表按真实情况生成；无的能力不建空目录凑数
- **full**（未做）：另含 skills 等

## index.md
任务类型 → 文件路径的地图。AGENTS 可指向此文件。保持短表。

## 模块文档 `components/<module>/`
建议 `README.md`（或单文件 `components/<module>.md`，全仓统一一种）。
必含：职责、边界（不做的）、主要接口/入口路径、依赖、雷区（若有）、相关代码路径。
禁止：粘贴大段源码；写其它模块的说明书。

## architecture
- overview：系统是什么、主路径、关键结构
- boundaries：负责/不负责、模块边界
- adr：重要决策（上下文/决策/弃选/后果）

## development
setup 环境；commands 常用命令；testing 怎么测、惯例。

## operations
deploy；config（不写密钥原文）；runtime 运行特征与排障入口。

## 单篇骨架（紧凑）
```markdown
# 标题
## 何时读
## 内容
## 相关
- 代码：
- 其它 docs：
```
可选：`验证于：<日期或 commit>`

## 生成规则（bootstrap）
1. 先扫代码与既有文档，再写；不确定标「待确认」，不编造
2. 只为真实模块建 components
3. 合并旧 AI 文档：代码 > 测试 > 已确认文档 > 历史 > 新生成
4. 文风：中文、紧凑、少空行

## 维护
代码变更后按规模走 sync/end。稳定事实只留 docs；memory 不重复。
