# Source
## 何时读
修改原图来源、ID 解析、文件修订或 MIME 检查时。
## 职责与接口
`SourceInterface::resolve(string $publicId): SourceImage`；唯一实现为 LocalSource。SourceImage 为 readonly，含 path、identity、modifiedAt、bytes、format。
- 构造时 realpath 根目录；resolve 校验 ID 段并在根内查找精确路径及 jpg/jpeg/png/webp/avif/gif 后缀候选。
- realpath 后必须仍在根内，且只能有一个唯一文件；零候选 404，多候选 409。
- 检查可读性、源字节限额、fileinfo MIME 与已有扩展名是否匹配；不支持/不匹配返回 415。
- identity 对路径、inode、mtime、ctime、size 哈希，**不是内容哈希**。
## 边界与依赖
依赖 Security\Limits、ImageException、PHP fileinfo/文件系统；不做像素解码、动画检查、变换或远程下载。
## 雷区
同 ID 的多个格式原图会冲突，投递扩展名不用于消歧。来源在 HTTP 和子进程中各解析一次；并发修改原图的整体一致性尚未验证。macOS 临时目录可能经 `/private/var` 解析，路径测试应比较 realpath。
## 相关
- 代码：`src/Source/SourceInterface.php`、`src/Source/SourceImage.php`、`src/Source/LocalSource.php`。
- 测试：`tests/v2/StorageAndUrlTest.php` 中本地解析和歧义用例。
- [Url](../Url/README.md)、[缓存](../Cache/README.md)。
验证于：2026-09-17。
