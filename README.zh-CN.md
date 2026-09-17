# EvaThumber 2

Cloudinary 兼容、可自托管的 PHP 图片变换服务。使用 Cloudinary 风格的 URL，EvaThumber 基于 libvips 从本地原图派生缩放、裁剪与重新编码的图片，落盘缓存并以标准 HTTP 缓存语义响应。

- PHP 8.5、strict types、Symfony 7.4 LTS HTTP 层
- libvips（`jcupitt/vips`）惰性流水线，低内存
- FrankenPHP worker 模式生产级 Docker 镜像（amd64/arm64）
- 独立 Composer 库核心（`EvaThumber\Image\Thumber`），HTTP 只是薄封装
- 不支持的参数显式报错——不做错误的静默近似

## 快速开始（Docker）

```bash
mkdir -p data/images && cp your-image.jpg data/images/
docker compose up --build
curl -o out.webp 'http://localhost:8081/image/upload/c_fill,w_300,h_300,f_webp/your-image.jpg'
```

通过 `EVATHUMBER_PORT` 修改宿主端口。原图目录只读挂载（`./data/images:/data/images:ro`），仅 `/data/cache` 可写。

## URL 语法

```
/{cloud_name}/image/upload/{transformation_chain}/{public_id}.{ext}
/image/upload/{transformation_chain}/{public_id}.{ext}
```

- 链式步骤用 `/` 分隔，同一组件内参数用 `,` 分隔（如 `c_fill,w_300,h_300/q_80`）。
- 输出扩展名（`.webp`、`.jpg` 等）决定投递格式；变换中的 `f_` 优先；`f_auto` 按 `Accept` 协商并附带 `Vary: Accept`。
- `v123` 版本段被接受但不参与解析。

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
| `q` | 1–100 | |
| `f` | `jpg`、`png`、`webp`、`avif`、`gif`、`auto` | `auto` 为 Accept 协商 |
| `b` | `rgb:RRGGBB` 或 `white`/`black`/`red`/`green`/`blue`/`transparent` | 填充背景 |
| `e` | `grayscale`、`negate` | |

支持格式：JPEG、PNG、WebP、AVIF、GIF（仅静态帧）。

### 明确不支持（返回 HTTP 400）

- `q_auto` —— Cloudinary 自适应质量算法未复刻，请用显式 `q`。
- `g_auto` / `g_face` —— AI 重心需要 Cloudinary 模型，不用其他算法冒充。
- 动图输入（多帧 GIF/WebP）—— 直接拒绝，不会静默取首帧。
- 远程抓取、SVG/PDF 源、图层叠加、文字、圆角（`r`）、模糊/锐化、`lfill`/`lpad`/`mfit`/`mpad`。
- 查询字符串；仅支持 GET/HEAD。

## 缓存与 HTTP

- 派生图缓存在磁盘，键 = 源修订 + 规范化变换 + 协商格式 + 策略版本；命中完全不触碰 libvips。
- 响应携带 `ETag`、`Last-Modified`、`Cache-Control: public, max-age=...`；条件请求返回 `304`。
- 并发 miss 时单个 worker 处理（有界准入锁），其余返回 `503` + `Retry-After`，不会堆积。
- 缓存容量（字节数与条目数）强制执行；写满返回 `507 cache_full` 而非无限增长。
- 所有处理运行于有超时上限的子进程，超时返回 `504 processing_timeout`。

## 配置（环境变量）

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `EVATHUMBER_SOURCE` | `/data/images` | 原图根目录（只读） |
| `EVATHUMBER_CACHE` | `/data/cache` | 派生图缓存（可写） |
| `EVATHUMBER_MAX_SOURCE_BYTES` | `33554432` | 源文件大小上限 |
| `EVATHUMBER_MAX_SOURCE_PIXELS` | `40000000` | 源像素总量上限 |
| `EVATHUMBER_MAX_SOURCE_DIMENSION` | `20000` | 源宽高上限 |
| `EVATHUMBER_MAX_OUTPUT_DIMENSION` | `4096` | 输出宽高上限 |
| `EVATHUMBER_MAX_OUTPUT_PIXELS` | `16000000` | 输出像素总量上限 |
| `EVATHUMBER_MAX_STEPS` | `8` | 链式变换上限 |
| `EVATHUMBER_MAX_URL_LENGTH` | `4096` | URL 长度上限 |
| `EVATHUMBER_TIMEOUT` | `15` | 处理子进程超时（秒） |
| `EVATHUMBER_CACHE_BYTES` / `_ENTRIES` | 1 GiB / 10000 | 缓存容量 |
| `EVATHUMBER_MAX_AGE` | `3600` | `Cache-Control` max-age |
| `EVATHUMBER_PHP_BINARY` | `PHP_BINARY` | 处理子进程所用 PHP |

## 库方式使用

```php
use EvaThumber\Image\Pipeline;
use EvaThumber\Source\LocalSource;
use EvaThumber\Transformation\Parser;

$source = (new LocalSource('/path/to/images'))->resolve('photo');
(new Pipeline())->write($source, (new Parser())->parse('c_fill,w_300,h_300'), '/tmp/out.webp', 'webp');
```

## 开发

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan level 8
docker compose up --build
```

## 兼容性矩阵

| 能力 | 状态 |
| --- | --- |
| 缩放/裁剪模式（`c_scale/fit/fill/crop/thumb/pad/limit`） | 支持 |
| 尺寸、`ar`、`dpr`、罗盘重心、NW 坐标裁剪 | 支持 |
| 直角旋转、翻转、灰度、反色 | 支持 |
| 输出格式 jpg/png/webp/avif/gif、`f`、扩展名投递、`f_auto` | 支持 |
| `q_auto` | 不适用（无等效算法，显式拒绝） |
| `g_auto`、人脸检测 | 计划中（需真实检测模型） |
| 动图 | 计划中 |
| 叠加/图层、文字、风格化效果（`r`、模糊、晕影等） | 计划中 |
| 远程/S3 源 | 计划中（源抽象已就绪） |
| 视频、Upload/Admin API | 不适用 |

从 EvaThumber 1.x 迁移见 [docs/migration-v1.md](docs/migration-v1.md)。

## License

BSD-3-Clause —— 见 [LICENSE](LICENSE)。
