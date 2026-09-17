# Url
## 何时读
修改 URL 语法、版本段、投递扩展名与 public ID 解析时。
## 职责与接口
`Parser::parse(string $path): ImageUrl` 接收原始 URL path，不依赖 Symfony Request。支持 `/[cloud_name/]image/upload/...`；ImageUrl 持有 publicId、format、transformation、cloudName、version。
- 检查 URL 字节长度，拒绝非法编码与编码后的路径分隔符；解码一次，拒绝残留 `%` 等非法字符。
- 首部类似 `a_...` 的路径段作为变换链，随后可读 `v数字`；版本段可以分隔类似变换名的 public ID。
- 最后扩展名是投递格式，移除后得到 publicId；jpeg 归一为 jpg；无扩展名则 format=null。
- cloudName/version 不参与原图目录选择或缓存身份。
## 边界与依赖
依赖 Transformation\Parser、Security\Limits、Exception\ImageException；不定位文件、不协商 Accept、不检查 Request 方法。
## 雷区
目录名与变换前缀存在语法歧义，不能随意改变段消费规则。输出扩展名不是原图格式保证。长度按 strlen 字节数计算。
## 相关
- 代码：`src/Url/Parser.php`、`src/Url/ImageUrl.php`。
- 测试：`tests/v2/StorageAndUrlTest.php::testUrlWithCloudVersionAndChain`。
- [参数规则](../Transformation/README.md)、[来源](../Source/README.md)。
验证于：2026-09-17。
