# Project Memory
全文限高 ≤150 行；超限先删 Assumed、过时或已升格 docs 的条目。
只记代码/docs 难表达且影响后续协作的信息；不写业务说明、架构复述或流水账。
置信：Confirmed（代码/测试/人确认）｜Assumed（待验证，用完删除或升格）。
更新：2026-09-27
## 当前焦点
- Confirmed：RC1 清单 14 项中 13 项已有本地实测证据，唯一 blocker 是第 14 项「当前 commit 的远程 CI 与镜像构建证据」。清单与实测数据见 `docs/progress.md`，不要在别处重复数字。
- Confirmed：开发主干已并到 `master`，用户要求后续改用 `main`。
- Confirmed：未授权打 tag 或发布镜像；本轮只推到 master。
## 雷区与禁忌
- Confirmed：外部旧会话记忆可能停留在中间状态；本仓 docs 与当前代码优先，不据旧任务清单重新实现已有模块。
- Confirmed：`docs/branch-review-315303a.md` 与 `bench/results/session-*`、`final-link` 是按 commit/时点记录的历史证据，其中的测试数字不要当作当前状态；当前状态文档只写 63 tests / 877 assertions（arm64 容器 uid 33）。
- Confirmed：CI 在容器内以 uid 33 跑测试。root 会让权限位断言（`/readyz` 对只读 cache）失去前提并被 skip，不要把 root 运行的结果当作该能力已验证。
## 调试手册
- Confirmed：OrbStack 上 `docker restart`/stop-start 会重新分配临时宿主端口（`-p 127.0.0.1::8080`），验收脚本重启后须重查 `docker port`。
- Confirmed：**OrbStack 会把 bind mount 的属主重映射成容器用户，Linux 宿主不会。** 因此挂载目录的权限问题（如 `0700` 导致容器内 uid 33 读不到）在 Mac 上永远测不出来，只有原生 CI 会暴露。验收脚本的 fixture 目录一律 `0755`。
- Confirmed：Caddy 的 graceful shutdown 无上界（本版本 Caddyfile 适配器不接受 `shutdown_delay`，只有 JSON 的 `http.servers.*.shutdown_delay` 才有）。约 4% 的 `docker stop` 会被残留连接拖满 20s；`serve.php` 此时 SIGKILL FrankenPHP、记 `shutdown_forced` 并仍 exit 0，只有池子被强杀才 exit 1。
- Confirmed：压测驱动 `bench/http-load.php` 的 `poolEvents()` 与日志归档已改 shell 侧流式过滤（grep `"event":` / `gzip`），避免高并发下 PHP 缓冲 `docker logs` OOM。
- Confirmed：Symfony `Process::start($callback)` 不会自行泵管道，检测「输出已到达」必须改用 `Process::fromShellCommandline('... >> file 2>&1')` 让 docker 直接写文件。
- Confirmed：libvips 按 inode 缓存已 mmap 的文件描述符；curl 原地覆写同一路径后 `Image::newFromFile()` 可能读到旧图头。验收脚本读响应一律用 `Image::newFromBuffer(file_get_contents(...))` 且每次用新文件名。
- Confirmed：默认 worker RSS 上限 192 MiB。4000×3000 源做 AVIF 或 `w_3000,h_3000` 输出会触发 `worker_memory_limit`（设计内 503 + 退避重启）；需要长编码窗口又留余量时用 WebP 交付。
- Confirmed：验收脚本的失败诊断不要用 `docker logs --tail N`——就绪轮询会刷满数百行 access log，把池子启动输出挤掉。要同时打 head 与 tail。
## 待验证
- 无额外 Assumed 条目。
## 协作偏好（项目级）
- Confirmed：采用 Standard + defaults；普通偏好/流程保持上游原文，项目特例写 docs。接入只建知识体系，不顺手修缺陷。
