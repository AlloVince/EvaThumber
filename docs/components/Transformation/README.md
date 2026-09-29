# Transformation
## 何时读
增改参数、组合限制、canonical 或缓存语义时。
## 职责与接口
- `Parser::parse(string): Transformation`：空串允许；`/` 分步骤，`,` 分参数；限制长度、步骤数、总参数数，同一步重复键拒绝。
- `Step(array $parameters)` 调用 `ParameterRules::normalize()`；不可变 parameters，提供 get/canonical。
- `Transformation(list<Step>)` 保持像素步骤顺序，提供 get/canonical；末尾 q/f 可分段，合并到第一个含投递参数的步骤。重复 q/f 或投递参数之后再执行像素步骤会拒绝。canonical 将步骤按 `/` 连接；Step 键排序。
## 规则摘要
- c：scale/fit/fill/crop/thumb/pad/limit；有尺寸而无 c 时补 scale。
- w/h：正整数或 0.x；ar 必须配 w/h 之一，不能三者同时给。dpr 在 1–4。
- g 限罗盘方向且模式须能定位；thumb 必须显式 g；x/y 仅 `c_crop,g_north_west`。
- resize、a、e 三类动作不能混同一步，需链式拆开。a 为直角/翻转；e 为 grayscale/negate。
- f 支持五种 raster 格式与 auto，jpeg 归一 jpg；q 为 1–100 或 auto[:best|good|eco|low]，auto 规范成 auto:good；未知档位与 sensitive 拒绝。自动质量为 [本地启发式](../Image/auto-quality.md)，不是 Cloudinary 等价算法；PNG/GIF 自动质量由 Pipeline 拒绝。b 仅 pad。
- w/h/dpr/x/y 与 ar 规范成数值字符串；不承诺所有语义等价表达式都合并为同一 canonical。ar 规范成**可无损回读**的定点十进制（`ParameterRules::decimal()`）：普通 `(string)` 只留 14 位有效数字，`16:9` 变 `1.7777777777778`，`w_600` 就会算成 337.4999… 而 round 到 337，输出 600×337 这种并非 16:9 的图；`var_export()` 虽无损但极值比例会出 `1.0E-5` 这类指数记法，被上面的 ar 文法拒绝。改动此规范化会变更缓存键（`ar_16:9` 现为 `ar_1.7777777777777777`），升级后旧条目一次性 miss。
## 边界与依赖
依赖 Security\Limits 与 ImageException；不读取图片、不判断实际源尺寸、不执行自动格式协商。
## 雷区
canonical 同时用于缓存身份与子进程传输。改变默认值/规范化顺序可能影响缓存命中。直接构建模型的调用方还会被 Pipeline 重新解析校验。
## 相关
- 代码：`src/Transformation/Parser.php`、`ParameterRules.php`、`Step.php`、`Transformation.php`（后三者同目录）。
- 测试：`tests/v2/PipelineTest.php` 的 unsupportedProvider、投递链等价/拒绝用例、>1 MPix 原图的 a_* 回归与 ar 尺寸用例；`tests/v2/StorageAndUrlTest.php` 的 canonical 断言与 ar 回读用例。
- [Image](../Image/README.md)、[Http](../Http/README.md)。
验证于：2026-09-17。
