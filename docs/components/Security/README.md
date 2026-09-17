# Security
## 何时读
调整资源边界或定位 image_too_large、参数限额时。
## 职责与接口
`Limits` 为 readonly；构造的各上限必须是 1..2147483647。`source(width,height)`、`output(width,height)` 校验正尺寸、单边上限与像素上限；通过 intdiv 比较避免像素乘法溢出。
调用分布：Source 检查字节，Transformation/Url Parser 检查长度/步骤/参数，Pipeline 检查源与各阶段输出尺寸。
默认值集中见 [配置](../../operations/config.md)，不在模块文档重复配置表。
## 边界与依赖
仅依赖 ImageException；不负责文件路径/MIME、进程超时、容器资源、HTTP 鉴权或请求限流。Limits 对象还参与 HTTP 缓存身份。
## 雷区
不能把 PHP 执行时间上限等同原生库墙钟超时。直接库调用没有 IsolatedProcessor 的截止时间。变换后最终尺寸小不代表中间尺寸不会越界。
## 相关
- 代码：`src/Security/Limits.php`、`src/Source/LocalSource.php`、`src/Image/Pipeline.php`、两个 Parser。
- 测试：现有集无独立 Limits 边界测试；覆盖缺口见 [testing](../../development/testing.md)。
验证于：2026-09-17。
