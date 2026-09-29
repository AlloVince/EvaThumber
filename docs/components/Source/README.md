# Source
## 何时读
修改原图来源、ID 解析、文件修订或 MIME 检查时。
## 职责与接口
`SourceInterface::resolve(string $publicId): SourceImage`；唯一实现为 LocalSource。SourceImage 为 readonly，含 path、identity、modifiedAt、bytes、format，以及可选的 readonly `snapshot: SourceSnapshot`。原有五参数构造和 path 的原路径语义保留。
- 构造时 realpath 根目录；resolve 校验 ID 段并在根内查找精确路径及 jpg/jpeg/png/webp/avif/gif 后缀候选。
- realpath 后必须仍在根内，且只能有一个唯一文件；零候选 404，多候选 409。
- `SourceSnapshot::read(path, maxBytes)` 打开单一普通文件描述符，最多读 maxBytes + 1 字节；即使并发增长也不无界读取。超限 413，读取失败或可见修订变化 409，文件不可用 404。
- 读取前后 fstat 与当前路径 stat 比较 dev/inode/mtime/ctime/size，排除 atime；长度须匹配。fileinfo MIME、SHA256 和 Pipeline 的显式 raster buffer loader **共享同一不可变压缩字节副本**，不再在解码时重开 path。扩展名与 MIME 不匹配返回 415。
- identity 保持原公式：路径、dev/inode、mtime/ctime/size 与该副本 SHA256。即使同秒原地改写或 ABA 绕过 stat 检查，identity 中的摘要仍对应实际解码字节，不会以 A 摘要缓存 B 像素。
- `assertIdentity(publicId, identity)` 再解析并比较身份，变化或解析失败返回 409 source_changed；HTTP、处理前后与发布前均复核。ABA 恢复 A 后允许复用 A 产物是安全的；不承诺捕获每次历史改写。
## 一致性决策与成本
选择有界压缩内存副本，而非仅持有 fd/重复 hash（挡不住 inplace/ABA），也不使用临时文件快照（额外磁盘 I/O、清理及强杀孤儿生命周期）。一个活跃 SourceImage 持有最多 maxSourceBytes 的压缩数据；读取探测可多 1 字节，PHP/libvips 分配与原生编码缓存另计，不是进程 RSS 硬上限。无新依赖/基础设施；对象释放即释放其 PHP 引用。
不是文件系统原子快照：无协作锁的写入者可能让一次读取混合多个版本；此时摘要绑定混合副本，解码可能 422，不能冒充旧摘要。若要求“必须对应某一完整上载版本”，上载端须采用原子 rename 或提供协作锁，本模块不负责上载。
手工构造的旧式 SourceImage 没有 snapshot 时，Pipeline 在入口重新作有界副本；其任意 caller-defined identity 是不透明的，不能提供 LocalSource 的内容身份保证。需要该保证的库调用方应使用 LocalSource。
## 边界与依赖
依赖 Security\Limits、ImageException、PHP fileinfo/文件系统；不做像素解码、动画检查、变换或远程下载。
## 验证与集成交接
`SourceConsistencyTest` 覆盖先 resolve 后替换、独立 PHP 写进程屏障协调的 rename-ABA/同 inode 等长 inplace（保留 mtime）、单遍 PNG 和 q_auto 双遍 JPEG、旧键缓存内容/HIT、边界字节与摘要、atime、持续改变拒绝发布。原实现失败回归：旧黑图期望像素 0，实际为替换图 200；快照实现通过。早期六用例版本重复 10 次通过；最终完整宿主 suite 51 tests / 641 assertions，PHPStan 通过（2026-09-17）。
写进程在整个 decode/encode 阶段保持 B，结束后恢复 A；这是确定性跨进程反例，不是实际 pool-worker 的随机压力或读取 syscall 中点故障注入。来源替换的 HTTP 与池路径验收已随完整 suite 与 `tests/crash-recovery.php` 通过；真实池 worker 解码期间的随机并发替换压力测试仍未做。当前完整 suite 77 tests / 949 assertions、PHPStan level 8 通过（arm64 Linux 容器内 uid 33），见 [进度](../../progress.md)。
`bin/transform.php` 与 `bin/pool-worker.php` 都在编码结束后先释放 source 再复核身份，避免同时持有两个完整副本；`pool-worker.php` 用 `finally { unset($source); }` 覆盖失败路径，常驻循环不会保留上一任务的 source。
## 雷区
同 ID 的多个格式原图会冲突，投递扩展名不用于消歧。每次 resolve（包括 HIT）均分配完整压缩副本并计算摘要，冷请求多次复核；完整 HTTP 命中/冷请求成本尚待测量。macOS 临时目录可能经 `/private/var` 解析，路径测试应比较 realpath。
## 相关
- 代码：`src/Source/SourceInterface.php`、`src/Source/SourceImage.php`、`src/Source/LocalSource.php`。
- 测试：`tests/v2/StorageAndUrlTest.php` 中本地解析和歧义用例。
- [Url](../Url/README.md)、[缓存](../Cache/README.md)。
验证于：2026-09-17。
