# Cache
## 何时读
修改缓存身份、并发准入、容量或发布机制时。
## 职责与接口
`DiskCache(root, maxBytes=1073741824, maxEntries=10000)`；`remember(identity, format, callable(string):void): CacheEntry`。条目 readonly：path、etag、modifiedAt、hit。
- 文件名为 SHA-256(identity + ':' + format) 加格式后缀；ETag 使用此键，不是输出内容摘要。
- 先查命中，无锁返回；未命中尝试缓存根目录 `.publish.lock` 非阻塞独占锁，再次检查命中。
- **同一缓存目录所有 miss 共享一把锁**，不是 per-key 或 lock stripes；繁忙 503 processor_busy。
- 锁内扫描合法条目统计容量并清理废弃 `.tmp-*`；容量超限 507，不做淘汰。
- producer 写临时文件，检查非空与剩余容量，再 rename 原子发布；finally 清理临时文件、释放锁。
## 边界与依赖
仅依赖 ImageException 与本地文件系统；不理解变换、来源修订或 encoder 版本。身份由 Http 构造；当前只有策略字符串，未自动纳入实际 codec 版本。
## 雷区
旧条目不会自动过期删除，HTTP max-age 也不是磁盘 TTL。不要在线直接删锁文件或无协调清缓存；运维清理方式尚待制定。独立身份却同根的请求也会竞争准入。
## 相关
- 代码：`src/Cache/DiskCache.php`、`src/Cache/CacheEntry.php`、`src/Http/Kernel.php`。
- 测试：`tests/v2/StorageAndUrlTest.php` 中命中绕过 producer、容量失败不发布/无残留临时文件。
- [运行与排障](../../operations/runtime.md)、[Http](../Http/README.md)。
验证于：2026-09-17。
