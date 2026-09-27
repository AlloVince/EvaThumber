# EvaThumber 2

可自托管的图片变换服务。使用 Cloudinary 风格的变换 URL，EvaThumber 基于 libvips 从本地原图派生缩放、裁剪与重新编码的图片，落盘缓存并以 HTTP 缓存语义响应。PHP 8.5 + FrankenPHP，单一非 root Docker 镜像。

[English README](README.md)

## 快速开始

```bash
docker run -p 8080:8080 -v /path/to/your/images:/data/images:ro docker.io/allovince/evathumber
```

然后请求一个变换：

```bash
curl -o out.webp 'http://localhost:8080/image/upload/c_fill,w_300,h_300/f_webp/your-image.jpg'
```

这就是全部配置。你唯一需要理解的业务参数是图片目录，以只读方式挂载到 `/data/images`。其他一切——worker、缓存、超时、上限——都有合理默认值。

发布的镜像支持 `linux/amd64` 与 `linux/arm64`，以非 root 用户运行，不需要配置文件、migration 或初始化命令。`docker.io/allovince/evathumber` 指向最新发布版本；需要固定版本时请用 `docker.io/allovince/evathumber:2.0.0`。

> 图片目录必须能被容器内用户（uid 33）读取。`mkdir -m 700 ~/photos` 这类私有目录在 macOS 上可用，但 Linux 容器读不到；请对挂载目录使用 `chmod 755`（或组可读）。配置错误时 `/healthz` 仍返回 200，但 `/readyz` 会报 `"source": false`，图片请求返回 404。

### 缓存

派生图写入容器内 `/data/cache`。默认不挂载任何东西，因此缓存是临时的：随容器消失，永远不需要备份。如需跨重启保留派生图，加一个卷：

```bash
docker run -p 8080:8080 \
  -v /path/to/your/images:/data/images:ro \
  -v evathumber-cache:/data/cache \
  docker.io/allovince/evathumber
```

缓存是可重建的派生数据：删除它不影响原图，服务会按需重新生成。`/healthz` 是存活探针；`/readyz` 是就绪探针（缓存不可写时返回 503）。

## URL 语法

```
/image/upload/{transformation_chain}/{public_id}.{ext}
/{cloud_name}/image/upload/{transformation_chain}/{public_id}.{ext}
```

- 链式步骤用 `/` 分隔，同一组件内参数用 `,` 分隔（如 `c_fill,w_300,h_300/q_80`）。
- 投递扩展名（`.webp`、`.jpg` 等）决定输出格式；链中 `f_` 优先；`f_auto` 按 `Accept` 协商（附带 `Vary: Accept`；同质量时 webp 优先）。
- `v123` 版本段参与缓存身份、不改变原图定位；它不是历史快照，不启用 `immutable`。

### 支持的参数

| 参数 | 取值 | 说明 |
| --- | --- | --- |
| `c` | `scale`（默认）、`fit`、`fill`、`crop`、`thumb`、`pad`、`limit` | Cloudinary 语义；`thumb` 必须显式 `g` |
| `w`、`h` | 整数像素或 `0.x` 相对值 | 省略一维时按纵横比推算 |
| `ar` | `4:3` 或小数 | 需要同时给出 `w` 或 `h` 之一作为锚 |
| `g` | 罗盘方向：`center`、`north`、`north_east` 等 | 用于 `fill`/`crop`/`thumb`/`pad` 定位 |
| `x`、`y` | 非负整数 | 仅限 `c_crop,g_north_west` |
| `dpr` | 1.0–4.0 | 目标尺寸倍率 |
| `a` | `0`、`90`、`180`、`270`、`-90`、`hflip`、`vflip` | |
| `q` | 1–100、`auto[:best\|good\|eco\|low]` | 自动质量为本地内容自适应启发式，仅 JPEG/WebP/AVIF，默认 good |
| `f` | `jpg`、`png`、`webp`、`avif`、`gif`、`auto` | `auto` 为 Accept 协商 |
| `b` | `rgb:RRGGBB` 或 `white`/`black`/`red`/`green`/`blue`/`transparent` | 填充背景 |
| `e` | `grayscale`、`negate` | |

支持格式：JPEG、PNG、WebP、AVIF、GIF（仅静态帧）。

### 明确不支持（返回 400/404/415，不做静默近似）

- `q_auto:sensitive`、未知质量档位以及 PNG/GIF 输出的 `q_auto`。已支持档位使用[本地启发式](docs/components/Image/auto-quality.md)，不是 Cloudinary 感知算法。
- `g_auto` / `g_face` —— AI 重心需要 Cloudinary 模型，不用其他算法冒充。
- 动图输入（多帧 GIF/WebP）—— 直接拒绝，不会静默取首帧。
- 远程/S3 抓取、SVG/PDF 源、图层叠加、文字、圆角（`r`）、模糊/锐化、`lfill`/`lpad`/`mfit`/`mpad`。
- 标量 `_a`/`_i` analytics 之外的查询参数（这两个参数不影响图片身份）；仅支持 GET/HEAD。

## 缓存与 HTTP 行为

- 派生图缓存在磁盘，键 = 源修订 + 规范化变换 + 协商格式 + 策略版本；命中完全不触碰 libvips。
- 响应携带 `ETag`、`Last-Modified`、`Cache-Control: public, max-age=3600`；条件请求返回 `304`。
- 冷请求有准入上限：服务饱和时 miss 快速返回 `503`（`Retry-After: 1`）而不是排队堆积。同键 miss 共享一次变换。处理超时返回 `504`。
- 容量按最老写入时间淘汰空闲条目；单产物超容量返回 `507 cache_full`。
- 过载时你只会看到 `503`，不会看到错误图片。系统能排多少排多少，其余有界拒绝，负载下降后自动恢复。

## 性能

生产 Docker 镜像实测（arm64，2 CPU / 512 MiB 限制，默认 2 个变换 worker），本地 fixture，每场景 8 秒窗口。完整原始证据：[`bench/results/rc1-http-full/`](bench/results/rc1-http-full/)。

| 场景 | 并发 | 成功率 | 成功吞吐 | p50 / p95 / p99 ms |
| --- | ---: | ---: | ---: | ---: |
| 热缓存 | 16 | 100%（37,740/37,740） | ~4,700/s | 3.3 / 5.7 / 7.1 |
| 同键冷启动 | 16 | 98.7%（6,216/6,301） | ~775/s | 14.8 / 62.9 / 75.3 |
| 不同键冷启动 | 4 | 100%（204/204） | ~25/s | 161 / 173 / 182 |
| 不同键冷启动 | 16 | 2%（68/3,417），其余有界 503 | ~8/s | 804 / 915 / 1,613 |
| 混合 | 16 | 15.7%（868/5,556），其余有界 503 | ~107/s | 2.8 / 612 / 1,134 |

说明：

- 同键冷启动在每种并发（2–16）下都只执行了**一次**变换；并发等待者复用该结果。
- 所有 503 均为 `cache_admission_full` / `processor_busy`（另有 1 次 `queue_timeout`）——即设计的有界拒绝。健康探测全程 200，每个容器 exit 0 且无 OOM，后续场景立即恢复。
- 冷启动吞吐刻意保持较小（2 worker，每次派生约 70–80ms）。如需更多并行派生，可调 `EVATHUMBER_POOL_SIZE`。

这些数字来自一台机器、一种图片形态和一组 fixture，请当参考点而不是承诺。报告中每个 200 响应都做了逐像素解码，32 个场景零失败。

## 故障下的正确性

`tests/crash-recovery.php` 在最坏的时刻杀掉服务，并检查已发布的缓存永远不是错的。每次 kill 都以 supervisor 日志（任务已开始且未结束）与 worker 私有 staging 文件双重证明落在编码中途；每个恢复后的产物必须与"从未崩溃的容器"逐字节一致。

| 场景 | 在途请求 | 容器 | 重启后 |
| --- | --- | --- | --- |
| 忙碌 worker `SIGKILL` | 有界 503 + 一个合法 200 | 服务不降级，`/healthz` 200 | 约 24ms 就绪，产物逐字节一致 |
| 容器 `SIGKILL`（PID 1） | 无响应，符合预期 | 容器消失 | 约 30ms 就绪，产物逐字节一致，无残留 |
| 池 supervisor `SIGKILL` | 有界 503 | 容器自行退出 | 约 34ms 就绪，产物逐字节一致，无残留 |
| 编码中 `docker stop` | 两个请求都拿到完整 200 | exit 0，无 OOM | 约 25ms 就绪，缓存仍然 HIT |

四种场景都不会留下 staging 或缓存临时文件。证据：[`bench/results/rc1-crash-recovery/`](bench/results/rc1-crash-recovery/)。

## 库方式使用（Composer）

```php
use EvaThumber\Image\Pipeline;
use EvaThumber\Source\LocalSource;
use EvaThumber\Transformation\Parser;

$source = (new LocalSource('/path/to/images'))->resolve('photo');
(new Pipeline())->write($source, (new Parser())->parse('c_fill,w_300,h_300'), '/tmp/out.webp', 'webp');
```

库核心独立于 HTTP 层（不包含 HTTP 缓存、准入与协商）。

## 开发

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan level 8
```

Docker 验收套件（需要 Docker daemon 和 `upload/` 中的 fixture）：

```bash
docker build --platform linux/arm64 --target production -t evathumber:2.0.0 .
php tests/container-smoke.php    evathumber:2.0.0 linux/arm64
php tests/rc1-acceptance.php    evathumber:2.0.0 linux/arm64
php tests/product-acceptance.php evathumber:2.0.0 linux/arm64
php tests/docker-acceptance.php  evathumber:2.0.0 linux/arm64
php tests/crash-recovery.php    evathumber:2.0.0 linux/arm64
```

所有套件都按 Quick Start 的方式运行镜像：只传 `-p` 和只读原图挂载，不挂缓存卷、不加调优参数。`crash-recovery.php` 还会在编码中途分别强杀忙碌 worker、容器本身和池 supervisor，并证明每个恢复后的产物与"从未崩溃的容器"逐字节一致。

CI 在原生 `linux/amd64` 与 `linux/arm64` runner 上跑完整套件：63 tests / 877 assertions、PHPStan level 8、每个架构五套验收，然后再用 buildx 构建双架构镜像。最近一次全绿记录：[`bench/results/rc1-ci/`](bench/results/rc1-ci/)。

架构、验收状态与发布门槛见 [`docs/`](docs/index.md)。

## 兼容性矩阵

| 能力 | 状态 |
| --- | --- |
| 缩放/裁剪模式（`c_scale/fit/fill/crop/thumb/pad/limit`） | 支持 |
| 尺寸、`ar`、`dpr`、罗盘重心、NW 坐标裁剪 | 支持 |
| 直角旋转、翻转、灰度、反色 | 支持 |
| 输出格式 jpg/png/webp/avif/gif、`f`、扩展名投递、`f_auto` | 支持 |
| `q_auto[:best\|good\|eco\|low]` | JPEG/WebP/AVIF 的本地内容/格式自适应 Q，不等价于 Cloudinary |
| `g_auto`、人脸检测 | 不支持（需真实检测模型） |
| 动图 | 不支持 |
| 叠加/图层、文字、风格化效果（`r`、模糊、晕影等） | 不支持 |
| 远程/S3 源 | 不支持 |
| 视频、Upload/Admin API | 不适用 |

从 EvaThumber 1.x 迁移见 [docs/migration-v1.md](docs/migration-v1.md)。

## License

BSD-3-Clause —— 见 [LICENSE](LICENSE)。
