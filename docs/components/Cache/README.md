# Cache
## 何时读
修改缓存身份、并发准入、容量或发布机制时。
## 职责与接口
`DiskCache(root, maxBytes=1073741824, maxEntries=10000)`；`remember(identity, format, callable(string):void): CacheEntry`。条目包含 path、etag、modifiedAt、hit，并持有文件共享租约，析构时释放。
- 文件名为 SHA-256(identity + ':' + format) 加格式后缀；ETag 使用此键，不是输出内容摘要。
- 命中取得条目共享锁并复核 inode，绕过准入槽位与全局发布锁。
- 每个缓存根目录固定 **8 个跨进程准入槽位，包括唯一 producer**；miss 以非阻塞 `flock` 尝试 `.admission-0.lock` 至 `.admission-7.lock`。槽位全满立即抛出 503 processor_busy，不进入发布锁等待循环；HTTP 已有 Retry-After: 1。
- 获准 miss 再尝试根目录 `.publish.lock` 非阻塞独占锁，取得锁后复查命中。所有键仍共享这把全局发布锁；忙锁时最多等待 250ms，以最长 10ms 间隔轮询并复查目标条目，同键可复用刚发布产物，超时仍 503 processor_busy。不是 per-key single-flight 或 lock stripes，不同键也竞争此锁。
- producer 完成后才按最老写入时间淘汰至满足字节/条目限额；命中不更新时间，不是 LRU。淘汰尝试非阻塞独占锁，跳过使用中的条目；全部占用时返回 503 cache_busy。
- 单个产物为空或超过总字节上限时返回 507 cache_full。producer 失败不淘汰已有条目。
- 槽位覆盖等待、生产、容量回收与发布；临时文件经大小检查、腾出容量后 rename 原子发布。finally 清理临时文件、释放发布锁与槽位；进程退出/SIGKILL 时由操作系统释放文件锁，下一 producer 清理孤儿临时文件。
- HTTP 的 CachedFileResponse 持有条目至 sendContent() 结束（包括异常），或响应析构。库调用方必须持有 CacheEntry 至读取完成。
## 边界与依赖
仅依赖 ImageException 与本地文件系统；接口与依赖不变，槽位数固定、无新增配置。仅限制缓存层已准入 miss 数，不限制 HTTP 服务器队列；全局单 producer 瓶颈仍在，250ms 也不是整次请求或生产截止时间。不理解变换、来源修订或 encoder 版本。身份由 Http 构造；当前只有策略字符串，未自动纳入实际 codec 版本。
## 雷区
旧条目不会自动过期删除，HTTP max-age 也不是磁盘 TTL。任何实例运行期间禁止删除 `.admission-*.lock` 或 `.publish.lock`：稳定 inode 是跨进程协调的前提；锁文件存在不代表被占用，无需按 PID 清理。不要无协调清缓存；运维清理方式尚待制定。独立身份却同根的请求也会竞争准入。
## 相关
- 代码：`src/Cache/DiskCache.php`、`src/Cache/CacheEntry.php`、`src/Http/Kernel.php`。
- 测试：`tests/v2/StorageAndUrlTest.php`、`tests/v2/CacheLifecycleTest.php`、`tests/v2/CacheConcurrencyTest.php`、`tests/v2/HttpTest.php`：命中、容量回收、跳过租约、producer 失败保留缓存、跨进程同键复用、忙等截止时间；7 个占用槽位加真实 producer 填满 8 槽、溢出立即 503、命中绕过满槽、SIGKILL 后全部槽位恢复与孤儿临时清理；HTTP 503 Retry-After 后恢复。
- [准入 ADR](../../architecture/adr/0001-cache-admission.md)、[运行与排障](../../operations/runtime.md)、[Http](../Http/README.md)。
验证于：2026-09-17。
