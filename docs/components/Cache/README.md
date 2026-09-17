# Cache
## 何时读
修改缓存身份、并发准入、容量或发布机制时。
## 职责与接口
`DiskCache(root, maxBytes=1073741824, maxEntries=10000, waitMilliseconds=250)`；`remember(identity, format, callable(string):void, ?Closure $stage=null): CacheEntry`（可选 stage 回调只观测阶段、不得抛异常）。条目包含 path、etag、modifiedAt、hit，并持有文件共享租约，析构时释放。
- 文件名为 SHA-256(identity + ':' + format) 加格式后缀；ETag 使用此键，不是输出内容摘要。
- 命中取得条目共享锁并复核 inode，绕过准入槽位与全局发布锁。
- 每个缓存根目录固定 **8 个跨进程准入槽位，包括 producer 和同键等待者**；miss 以非阻塞 `flock` 尝试 `.admission-0.lock` 至 `.admission-7.lock`。槽位全满立即抛出 503 cache_admission_full，不进入发布锁等待循环；HTTP 已有 Retry-After: 1。
- 获准 miss 取得 `.key-XX.lock`（键摘要前两位，共 256 个稳定条带），等待时每最多 10ms 复查同键产物；默认等待 250ms，HTTP 传入 TIMEOUT + 队列等待 + 3 秒。同键只运行一个 producer；不同键可能发生条带碰撞。等待超时返回 503 cache_key_timeout。
- `.publish.lock` 只保护临时登记/清理、容量处理和原子发布，各次等待最多 250ms；producer 在锁外运行，不同条带可并行。登记或发布等待超时均返回 503 cache_publish_timeout；诊断阶段 cache_register_wait / cache_publish_wait 区分两处。
- producer 完成后才按最老写入时间淘汰至满足字节/条目限额；命中不更新时间，不是 LRU。淘汰尝试非阻塞独占锁，跳过使用中的条目；全部占用时返回 503 cache_busy。
- 单个产物为空或超过总字节上限时返回 507 cache_full。producer 失败不淘汰已有条目。
- 槽位覆盖等待、生产、容量回收与发布；临时文件经大小检查、腾出容量后，以同目录 `link(temporary, final)` 原子发布完整 inode，再 unlink 临时名称；发布锁与临时独占租约覆盖这两个操作。链接失败返回 503 cache_unavailable，不退回 copy+delete，也不覆盖已存在名称。finally 清理临时文件、释放发布锁与槽位；进程退出/SIGKILL 时由操作系统释放文件锁，下一 producer 清理孤儿临时名称，不影响已发布的硬链接。
- HTTP 的 CachedFileResponse 持有条目至 sendContent() 结束（包括异常），或响应析构。库调用方必须持有 CacheEntry 至读取完成。
## 边界与依赖
仅依赖 ImageException 与支持硬链接及 flock 的本地文件系统；临时文件与最终条目必须同目录/同文件系统。槽位数固定。仅限制缓存层已准入 miss 数，不限制 HTTP 服务器队列；同键等待者仍消耗槽位，池容量配置不能绕过此上限。发布锁 250ms 不是整次请求或生产截止时间。不理解变换、来源修订或 encoder 版本。身份由 Http 构造；当前只有策略字符串，未自动纳入实际 codec 版本。
## 雷区
旧条目不会自动过期删除，HTTP max-age 也不是磁盘 TTL。任何实例运行期间禁止删除 `.admission-*.lock`、`.key-*.lock` 或 `.publish.lock`：稳定 inode 是跨进程协调的前提；锁文件存在不代表被占用，无需按 PID 清理。不要无协调清缓存；运维清理方式尚待制定。独立身份却同根的请求也会竞争准入。
## HTTP 阶段诊断与发布停顿
- `Settings(serverTiming: true)` 或 `EVATHUMBER_SERVER_TIMING=1` 开启 `Server-Timing`；默认关闭，仅字符串 `1` 开启。计时为请求局部，成功/错误响应均可观测。`app` 不包含进入 Kernel 前的 HTTP 排队和响应体发送；`processor` 包含池等待、IPC 与处理，不等于纯变换时间。
- 阶段覆盖 source、source_before/after、cache_lookup/admission/key_wait、cache_register_wait/register、processor、cache_validate、cache_publish_wait、cache_eviction、cache_publish（link）、cache_unlink、cache_unlock/entry/cleanup、response。不同阶段的 p95 不应直接相加。
- 本次 ARM64 OrbStack、生产 FrankenPHP、源与缓存 tmpfs、2 CPU / 512MiB 的 10 秒冷请求 c1：修复前 69/69 HTTP 200，HTTP p95 **396.745ms**、rename 阶段 p95 **317.242ms**、processor p95 81.847ms；strace 测到 `renameat()` **337.842ms**。独立 CLI 的 100MB 文件 1000 次 rename 也出现 314.555ms；未定位内核内部等待原因，不能归因于 PHP stat cache 或某个锁持有者。
- 同环境改为 link+unlink 后 c1 120/120 HTTP 200，HTTP p95 **95.976ms**、发布 p95 **0.041ms**；c4 232/232 HTTP 200，HTTP p95 **194.721ms**、发布 p95 **0.058ms**。这是该环境的短时诊断，不是吞吐承诺；全局发布锁持有时间缩短，但不声称已独立复现历史 sustained 报告的每一种 503。
- 不增加 waiter 预算：本次证据指向锁内 rename 停顿，而非准入槽不足。8 槽、键等待预算、250ms 发布锁预算与淘汰策略不变。
- 复验入口 `tests/cache-http-timing.php IMAGE /absolute/new-evidence-dir [concurrency=1] [max-publication-p95-ms]`：10 秒、并发 1..8；保存 image.txt、requests.json、summary.json、container.log。可选门槛（本环境用 5ms）要求至少 20 次发布、全 200、link+unlink 合计 p95 不超限；未设门槛时仅诊断。需要 Docker、PHP curl 和本地 `upload/demo.jpg`，图像沿用现有 fixture 放大方式。
- 修复前证据 `/tmp/eva-admission-before-detail-c1`、syscall 记录 `/tmp/eva-admission-trace-c1`；修复后 `/tmp/eva-admission-after-c1`、`/tmp/eva-admission-after-c4`。这些是本机临时证据，非仓库持久产物。诊断镜像专用 `evathumber:admission-arm64`，每次记录不可变 image ID。
- 新回归 `tests/v2/CachePublicationTest.php` 同步暂停在 link 后/unlink 前，断言完整内容、同 inode、租约保护；SIGKILL 后已发布内容存活且孤儿清理只删除临时名。另验证键超时和生产后发布超时的类别、清理与恢复。`tests/v2/ServerTimingTest.php` 验证默认关闭、环境开关、MISS/HIT/错误间计时不泄漏。
## 相关
- 代码：`src/Cache/DiskCache.php`、`src/Cache/CacheEntry.php`、`src/Http/Kernel.php`。
- 测试：`tests/v2/StorageAndUrlTest.php`、`tests/v2/CacheLifecycleTest.php`、`tests/v2/CacheConcurrencyTest.php`、`tests/v2/HttpTest.php`：命中、容量回收、跳过租约、producer 失败保留缓存、跨进程同键复用、忙等截止时间；7 个占用槽位加真实 producer 填满 8 槽、溢出立即 503、命中绕过满槽、SIGKILL 后全部槽位恢复与孤儿临时清理；HTTP 503 Retry-After 后恢复。
- [准入 ADR](../../architecture/adr/0001-cache-admission.md)、[运行与排障](../../operations/runtime.md)、[Http](../Http/README.md)。
验证于：2026-09-17。
