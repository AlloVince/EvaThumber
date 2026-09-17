# Exception
## 何时读
调整领域错误、HTTP/子进程错误映射或排障时。
## 职责与接口
`ImageException extends RuntimeException`；构造 `(string message, int status=400, string error='invalid_transformation')`，status/error 为 readonly 公共字段。
Parser/Source/Pipeline/Cache 抛出，HTTP 映射 JSON；内部处理子进程仅回传 status/error，由 IsolatedProcessor 生成概括 message。
## 边界与依赖
只依赖 PHP RuntimeException；不自行记录日志、不生成 Response，也不保证所有异常都是此类型（无效配置可抛 InvalidArgumentException）。
## 雷区
客户端错误不要包含本地路径或内部堆栈。文档不能将所有拒绝概括为 400：包括 404/409 来源问题、413 限额、415 格式/动画、422 无效图片、503 准入、504 超时、507 容量。未知异常由 Kernel 默认转 500；完整映射见运行文档。
## 相关
- 代码：`src/Exception/ImageException.php`、`src/Http/Kernel.php`、`src/Image/IsolatedProcessor.php`、`bin/transform.php`。
- 测试：`tests/v2/PipelineTest.php`、`tests/v2/StorageAndUrlTest.php`、`tests/v2/HttpTest.php` 仅覆盖部分错误分支。
- [排障表](../../operations/runtime.md)。
验证于：2026-09-17。
