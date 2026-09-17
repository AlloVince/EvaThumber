# Sustained local HTTP evidence — 2026-09-17

Scope: only `bench/http-load.php` and this evidence directory. No production edits, commits, remote tests or downloaded fixtures. Results are new Git-visible files (not staged/committed). Main owns production/cache/admission follow-up and docs.

## Authoritative run

`session-sustained-final/report.json`: 48 scenarios, 3 rounds × 8 seconds × c4/c16 × hot/same/different/mixed × pool/isolated. **429,275 load completions; 1,534 ongoing health/HIT probes**. No transport/decode/invariant failures, no container OOM, all 12 containers exited 0. This is bounded sustained closed-loop load, not an open-loop capacity/SLO guarantee. Two extra probe lanes are outside the reported load concurrency.

Built current Dockerfile production linux/arm64 as `evathumber:session-arm64`, not stale `evathumber:pool-arm64`. Full matrix uses immutable image **sha256:f54863f1cae538e6805b8c84b63a89fd7b4a9ea9f29a8d8517be8b7edc167806**. Build log is `session-fresh-single-worker/build.log`. Initial diagnostic build had a different image ID; see its report.

Production source manifest digest, initial and final: **cbdf27163b23747434eaaeb15673aace0937a6b129c4ca667f59bafd233555f7**. No observed production source changes during this work; this is NOT evidence for any later main-session cache/admission edits. `image-source.json` hashes executable image files and matches initial source. Git HEAD: `315303a49d1ee362c9e13b0713e7453fca6ea1cb`; dirty tracked diff SHA256 plus every production file SHA256 (including untracked code), benchmark SHA256, environment/image metadata and final source snapshot are persisted. Tags are not provenance; image IDs/manifests are.

Runtime: production defaults (2 pool workers / 4 queue / 1s queue deadline / 15s transform deadline / 16 HTTP workers), 512MiB cgroup, 2 CPUs, 256 PIDs, nonroot/read-only/cap-drop ALL. Pool and isolated use the SAME image ID, cache layout and limits; isolated disables socket and directly launches the same FrankenPHP config. Fresh disposable container per mode/round/concurrency, cache retained across its four scenarios. Round 2 reverses mode order. No other workloads were explicitly stopped, so host noise is not controlled.

## Three-round results

Successful RPS = 200 responses completed within the fixed 8s window, excluding probes and final drain. Median across 3 rounds. p95 range is the per-round **200-only** p95; it is not pooled percentiles. Detailed p50/p95/p99 by HTTP status and request class, counts, errors and cache ratios are in JSON.

| c | scenario | pool median success/s | isolated median success/s | pool 200 p95 range ms | isolated 200 p95 range ms | pool / isolated 503 totals |
|---|---|---:|---:|---:|---:|---:|
| 4 | hot | 3624.25 | 3514.50 | 1.69–1.86 | 1.58–1.91 | 0 / 0 |
| 4 | same initially cold | 663.50 | 634.50 | 7.38–8.54 | 8.17–11.34 | 0 / 0 |
| 4 | different cold | 14.50 | 7.50 | 464.95–517.30 | 557.78–675.95 | 28 / 71 |
| 4 | mixed | 16.625 | 12.125 | 518.72–551.10 | 631.24–901.08 | 56 / 115 |
| 16 | hot | 3586.75 | 3088.375 | 7.09–8.81 | 7.66–10.14 | 0 / 0 |
| 16 | same initially cold | 623.125 | 632.50 | 64.24–68.06 | 36.98–63.53 | 199 / 316 |
| 16 | different cold | 7.625 | 5.375 | 1159.11–1396.93 | 1004.62–1127.75 | 4837 / 5776 |
| 16 | mixed | 52.00 | 79.25 | 1048.21–1099.77 | 76.50–202.52 | 6049 / 10472 |

**Do not rank c16 mixed processing capacity by aggregate success/s**: quick cold rejections advance the fixed mixture and cheap HITs dominate successful responses. Inspect class/status breakdown and pool job counts. Fixed mixture is by launched request count, not successful count. c4 cold pool improves useful throughput but still rejects work and has substantial non-job tail.

Sampled cgroup memory peaks: pool 431.9MiB, isolated 405.6MiB (includes page cache/tmpfs/observer). c4 different-cold CPU averages approximately pool 1.85–1.87 cores, isolated 1.74–1.77. c16 different-cold approaches the 2-core limit. Every scenario has 100ms cgroup CPU/throttling, process summed RSS, per-PID/role RSS peak, sampled worker RSS and process CPU ticks. RSS sums shared pages; sampled per-worker peaks can miss short-lived isolated workers. Observer overhead included; cgroup CPU is authoritative.

All health/HIT probes returned 200 and HIT remained HIT, **but latency is not guaranteed**: worst probe was 1270.222ms under overload. See per-scenario probe percentiles rather than treating survival as responsiveness.

All six pool same-key scenarios started **exactly one job**, with one output SHA256. Same-key scenario means one cold fill followed by natural HITs, not continuously cold load. Every successful image was hashed; every distinct hash fully decoded by libvips pixel evaluation after measurement. Full-matrix outputs match across rounds/modes for each transform (9 class labels, same/different share bytes).

## Rejection and ~300ms stall diagnosis

- `session-initial-c2`: 12s/c2 pool 11 processor_busy, isolated 2. Pool logs have **zero corresponding pool rejections**. Initial mixture was flawed because probes incremented the load-class sequence; do not use this run to compare mixed capacity. Retained as early evidence only.
- `session-fresh-single-worker`: fresh build, one-worker diagnostic, corrected mixture. c1: 75 HTTP 200, zero rejection. c2: 67 HTTP 200, 10 processor_busy, **zero pool rejection events**, queue maximum 1. Not genuine pool saturation evidence.
- Final matrix c4: all pool processor_busy responses have zero pool rejection events. Pool jobs can exceed successful HTTP misses: jobs completed but HTTP/cache returned 503. Aggregate subtraction identifies non-pool rejection only, not exact key/admission/publication-lock site. Under c16, pool rejection events do occur; r3 mixed includes four proven queue_timeout responses. Raw event logs retain sequence/monotonic time/active/queued fields.
- `session-c1-diagnostic`: one in-flight cold load plus independent probes, 53 jobs/53 HTTP 200, no CPU throttling. Pool job p95 **87.316ms**, HTTP p95 **428.932ms**. Eleven slow requests have **272.6–359.8ms** client-minus-job overhead. Caddy duration matches client total within ~1ms, TTFB nearly equals total. Thus the repeated tail is server-side outside recorded pool job execution, not DNS/connect, client body transfer, or a full pool queue.
- `session-curl-bind` / `session-curl-tmpfs-complete`: independently spawned ordinary curl, 32 serial cold requests, reproduces p95 **425.184 / 406.524ms**. Eliminates curl_multi event-loop artifact. Same immutable image; changing only source storage from host bind to container tmpfs does NOT remove stalls. Sustained c1 HTTP p95 **373.783 / 378.154ms**, job p95 **78.662 / 78.182ms**.
- Same-image CLI source span samples: LocalSource::resolve p95 **2.465 / 2.236ms**, SourceSnapshot::read p95 **2.262 / 2.158ms**, finfo p95 **0.044 / 0.043ms** (bind/tmpfs). These are isolated CLI samples, NOT instrumented HTTP spans; cannot categorically exclude all HTTP source effects.

**Blocker: exact production function causing the extra 300ms is NOT proven.** No production instrumentation was authorized. Evidence narrows to server-side non-job phases; cache publication/admission is a candidate, not an established root cause. Need main-owned temporary spans around resolve, cache key/admission/publication, pool write/response, prepare/send, or a reviewed independent tracing run. No production changes were made here.

## Fixtures / representativeness

Existing local `upload/demo.jpg` (300×200, 24,843 bytes), `face.jpg` (300×433, 56,412 bytes), `blend.png` (300×200, 85,640 bytes). SHA256 and source metadata in each report. Upstream author/license unknown; not redistributed. Large source is **demo.jpg enlarged 8× to 2400×1600, JPEG Q90** by local libvips. This exercises larger pixel/encoding work but is **not a real native high-resolution photo**. No download/network fixture acquisition. Mixed launches cycle hot WebP, small JPEG resize, PNG crop→WebP, AVIF, large WebP, q_auto WebP, small WebP. Version segments make cold keys genuinely distinct.

## Evidence layout and failures retained

- `report.json`: summary + options/method/provenance/fixtures/full decode hash records.
- `*.requests.jsonl.gz`: every load/probe completion: slot/class/start/end, status, total/TTFB/connect time, bytes, cache, error, body SHA256.
- `*.resources.json`: 100ms cgroup/process samples and peaks.
- `*.pool-events.json`: pool events for the measurement only; complete Caddy/supervisor logs in `*.log.gz`.
- `initial-source.json`, `after-build-source.json` where built, `image-source.json`, `final-source.json`: source/build/run identity.
- `session-sustained`: **ABORTED, not authoritative**. Host PHP 128MiB exhausted during same scenario; first hot scenario only. Fixed benchmark cURL header closure reference cycle, released prior batch arrays, then reran full matrix with host memory_limit=1G. Container was explicitly removed. This was NOT service OOM. Never infer a pass from this partial report lacking finished_utc.
- `session-curl-tmpfs`: **FAILED setup**, Docker cp refuses a read-only rootfs even for this destination. Failure preserved. Replaced copy with docker exec stdin write into the already writable tmpfs; completed evidence is `session-curl-tmpfs-complete`.
- No tests weakened. PHP lint and editor diagnostics clean; `git diff --check` clean. Full PHPUnit/lifecycle suite not rerun by this scoped benchmark task. All benchmark containers cleaned up.

## Repeat (local only, fresh output directory required)

```sh
php -d memory_limit=1G -d ffi.enable=true bench/http-load.php --build --image=evathumber:session-arm64 --out=bench/results/repeat-current --seconds=8 --rounds=3 --concurrency=4,16 --scenarios=hot,same,different,mixed --modes=pool,isolated
```

Quick independent curl + c1 diagnostic (no production edits):

```sh
php -d memory_limit=1G -d ffi.enable=true bench/http-load.php --image=evathumber:session-arm64 --out=bench/results/repeat-curl --seconds=8 --rounds=1 --concurrency=1 --scenarios=different --modes=pool --curl-diagnostic
```

Optional `--source-storage=tmpfs` only for source-storage comparison. Host prerequisites: existing upload fixtures, Composer dependencies, PHP curl/FFI/zlib + native libvips, local Docker. Driver refuses overwriting evidence. Keep 1GiB host limit because raw completion arrays are accumulated in memory; server memory limit remains 512MiB. Current driver has optional diagnostic additions made after full-matrix measurement, with the same measuredLoad workload logic; execution-time driver hashes are recorded. Rebuild/repeat after any main-session production edit; never merge initial/final measurements across differing source hashes as though identical.
