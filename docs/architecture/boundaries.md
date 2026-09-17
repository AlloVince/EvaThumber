# 模块边界
## 何时读
分配职责、跨模块修改或评估架构影响时。
## 职责与依赖
| 模块 | 拥有的职责/数据 | 直接项目依赖 | 不做什么 |
|---|---|---|---|
| Url | ImageUrl、路径解释 | Transformation、Security、Exception | 原图读取、HTTP Request 处理 |
| Transformation | 参数规则、Step、Transformation、canonical | Security、Exception | 图像 I/O、格式协商 |
| Source | 来源定位、SourceImage、来源 identity | Security、Exception | 变换、缓存派生图 |
| Image | libvips 图、编码、库 facade；服务隔离适配器 | Source、Transformation、Security、Exception；IsolatedProcessor 还依赖 Http\Settings | HTTP 响应、缓存键管理 |
| Cache | 临时文件、缓存条目、每根固定 8 个跨进程 miss 槽位（含唯一 producer）、全局发布锁、容量淘汰、文件租约、发布 | Exception | HTTP 服务器队列、per-key single-flight、理解变换语义、格式协商、磁盘 TTL |
| Http | Settings、HTTP 编排、协商、缓存身份、响应 | Url、Source、Image、Cache、Security、Exception | 像素计算 |
| Security | Limits 参数与维度边界 | Exception | 鉴权、签名、限流、隔离进程 |
| Exception | ImageException 的 message/status/error | PHP RuntimeException | 日志、HTTP 响应生成 |
## 入口与耦合
- 公共 HTTP 入口 `public/index.php`；内部 stdin JSON 入口 `bin/transform.php` 不是公开命令 API。
- `Thumber`/`Pipeline` 不依赖 Http；但整个 Image 目录不能称为 HTTP 无依赖，`IsolatedProcessor` 使用 `Http\Settings`。
- Http 当前直接实例化 `LocalSource` 与 `DiskCache`；有 SourceInterface 不代表远程源可仅靠配置启用。
- `cloudName` 仅作兼容路径；`version` 参与 HTTP 缓存身份，但不提供租户隔离、鉴权或历史文件版本选择。
## 产品范围
支持静态 JPEG/PNG/WebP/AVIF/GIF 的有限缩放、裁剪、旋转、翻转、灰度和反色；q_auto 四档仅对 JPEG/WebP/AVIF 使用本地启发式，不等价于 Cloudinary 感知优化。不支持远程 fetch、SVG/PDF、动画处理、图层文字、AI gravity、视频及 Upload/Admin API。
Cloudinary 语法是既有兼容方向；实际支持集以 ParameterRules/Pipeline 与测试为准。未重新审计上游规范的逐项等价性。README 中 Planned 不是已批准交付承诺。
## 安全与运行边界
- 来源定位和 MIME 验证属于 Source；源/输出维度检测在 Pipeline；参数数量/长度检测在 Parser；截止时间属于 IsolatedProcessor。
- 路径校验、显式 raster loader 不等于已完成独立安全审计；容器内存/CPU限制也不等于库调用自带资源隔离。
## 相关
- 代码：`src/` 八个目录、`public/index.php`、`bin/transform.php`。
- [组件入口](../index.md)、[概览](overview.md)、[测试覆盖](../development/testing.md)。
验证于：2026-09-17。
