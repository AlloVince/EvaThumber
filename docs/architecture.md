# 架构文档入口（旧路径保留）
## 何时读
从旧链接进入时，转到按需加载的新文档；本页不再维护架构正文。
## 已迁移
- [架构概览与主数据流](architecture/overview.md)
- [模块边界与依赖](architecture/boundaries.md)
- [八个源码模块、开发与运维索引](index.md)
- [首扫证据与旧知识差异](agent-handbook.md)
原文中的 lock stripes、实际 encoder 版本入缓存键、Routing 使用情况已按源码更正，详见对应模块；没有改变实现。
## 相关
- 代码：`src/`、`public/index.php`、`bin/transform.php`。
- 历史兼容方向参考：[Cloudinary Image Transformation Reference](https://cloudinary.com/documentation/image_transformation_reference)。本次没有重新审计其逐项等价性。
迁移于：2026-09-17。
