# ADR 0001：缓存 miss 有界准入
状态：采用（2026-09-17）。
## 上下文
全局发布锁保证单 producer、临时文件清理和容量淘汰的一致性；250ms 截止仅限等待时长，未限制缓存层同时等待的请求数。
## 决策
每个缓存根固定 8 个跨进程文件锁槽位，包括唯一 producer。命中绕过槽位；满槽立即 503 processor_busy；获准 miss 仍在全局锁上最多等待 250ms。HTTP 返回 Retry-After: 1。不新增依赖或改变公共构造参数。
槽位文件 inode 必须稳定，运行中不得删除；异常通过 finally 释放，进程退出或 SIGKILL 由内核释放锁，无 PID 注册表和过期清理任务。
## 弃选
- 只限时间：无法限制缓存层等待者数量。
- 按键 single-flight / 并发 worker 池：需要重新协调全局容量淘汰、孤儿清理和子进程预算；当前不以此扩大实现。
## 后果
最多 8 个已获准 miss，正常单 producer 时最多 7 个等待者；固定保守容量，仍有全局生产者瓶颈，不保证公平。不限制 HTTP 服务器自身队列，不是按键 single-flight。共享根必须使用支持跨进程 flock 的本地文件系统；旧版本进程不遵守槽位，升级应停止旧实例后启动新实例。
## 证据
`tests/v2/CacheConcurrencyTest.php` 覆盖满槽拒绝、命中绕过、强杀释放、恢复和原有截止/同键复用；`tests/compose-admission.php` 经真实 Compose HTTP 验证 200 HIT、503/Retry-After 和释放后 200 MISS。
