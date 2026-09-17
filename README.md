# EvaThumber 2

Cloudinary-compatible, self-hosted image transformation service for PHP. Send Cloudinary-style transformation URLs; EvaThumber derives resized, cropped and re-encoded images from your local originals with libvips, caches them on disk and serves them with proper HTTP caching.

[中文文档](README.zh-CN.md)

- PHP 8.5, strict types, Symfony 7.4 LTS HTTP layer
- libvips (via `jcupitt/vips`) for lazy, low-memory processing
- FrankenPHP worker mode in a production-ready Docker image (amd64/arm64)
- Independent Composer library core (`EvaThumber\Image\Thumber`) with a thin HTTP layer on top
- Explicit failures for unsupported parameters — no silent approximations

## Quick start (Docker)

```bash
mkdir -p data/images && cp your-image.jpg data/images/
docker compose up --build
curl -o out.webp 'http://localhost:8081/image/upload/c_fill,w_300,h_300,f_webp/your-image.jpg'
```

Set `EVATHUMBER_PORT` to change the host port. Sources are mounted read-only (`./data/images:/data/images:ro`); only `/data/cache` is writable.

## URL syntax

```
/{cloud_name}/image/upload/{transformation_chain}/{public_id}.{ext}
/image/upload/{transformation_chain}/{public_id}.{ext}
```

- Chain steps with `/`, qualifiers with `,` (e.g. `c_fill,w_300,h_300/q_80`).
- The optional delivery extension (`.webp`, `.jpg`, …) sets the output format; `f_` inside a transformation overrides it; `f_auto` negotiates via `Accept` (adds `Vary: Accept`).
- A `v123` version segment is accepted and ignored for resolution.

### Supported parameters

| Parameter | Values | Notes |
| --- | --- | --- |
| `c` | `scale` (default), `fit`, `fill`, `crop`, `thumb`, `pad`, `limit` | Cloudinary semantics; `thumb` requires explicit `g` |
| `w`, `h` | integer pixels or `0.x` relative | One dimension may be omitted to preserve aspect ratio |
| `ar` | `4:3` or decimal | Requires `w` or `h` to anchor the other dimension |
| `g` | compass: `center`, `north`, `north_east`, … | For `fill`/`crop`/`thumb`/`pad` positioning |
| `x`, `y` | non-negative integers | Only with `c_crop,g_north_west` |
| `dpr` | 1.0–4.0 | Multiplies target dimensions |
| `a` | `0`, `90`, `180`, `270`, `-90`, `hflip`, `vflip` | |
| `q` | 1–100 | |
| `f` | `jpg`, `png`, `webp`, `avif`, `gif`, `auto` | `auto` = Accept negotiation |
| `b` | `rgb:RRGGBB` or `white`/`black`/`red`/`green`/`blue`/`transparent` | Pad background |
| `e` | `grayscale`, `negate` | |

Supported source/output formats: JPEG, PNG, WebP, AVIF, GIF (static frames only).

### Explicitly unsupported (rejected with HTTP 400)

- `q_auto` — Cloudinary's adaptive quality algorithm is not replicated; use explicit `q`.
- `g_auto` / `g_face` — AI gravity requires Cloudinary's models; not emulated.
- Animated inputs (multi-frame GIF/WebP) — rejected rather than silently flattening.
- Remote fetch, SVG/PDF sources, layer overlays, text, rounded corners (`r`), blur/sharpen effects, `lfill`/`lpad`/`mfit`/`mpad`.
- Query strings; GET/HEAD only.

## Caching and HTTP

- Derived images are cached on disk, keyed by source revision + canonical transformation + negotiated format + policy version. Hits bypass libvips entirely.
- Responses carry `ETag`, `Last-Modified`, `Cache-Control: public, max-age=...`; conditional `If-None-Match` returns `304`.
- Under concurrent misses, only one worker processes a key (bounded admission lock); others get `503` + `Retry-After` instead of piling up.
- Cache capacity (bytes and entry count) is enforced; full cache returns `507 cache_full` rather than growing unbounded.
- All processing runs in a timeout-bounded subprocess; a hard deadline returns `504 processing_timeout`.

## Configuration (environment)

| Variable | Default | Meaning |
| --- | --- | --- |
| `EVATHUMBER_SOURCE` | `/data/images` | Original images root (read-only) |
| `EVATHUMBER_CACHE` | `/data/cache` | Derived image cache (writable) |
| `EVATHUMBER_MAX_SOURCE_BYTES` | `33554432` | Max source file size |
| `EVATHUMBER_MAX_SOURCE_PIXELS` | `40000000` | Max source pixels |
| `EVATHUMBER_MAX_SOURCE_DIMENSION` | `20000` | Max source width/height |
| `EVATHUMBER_MAX_OUTPUT_DIMENSION` | `4096` | Max output width/height |
| `EVATHUMBER_MAX_OUTPUT_PIXELS` | `16000000` | Max output pixels |
| `EVATHUMBER_MAX_STEPS` | `8` | Max chained transformations |
| `EVATHUMBER_MAX_URL_LENGTH` | `4096` | Max URL length |
| `EVATHUMBER_TIMEOUT` | `15` | Processing subprocess deadline (seconds) |
| `EVATHUMBER_CACHE_BYTES` / `_ENTRIES` | 1 GiB / 10000 | Cache capacity |
| `EVATHUMBER_MAX_AGE` | `3600` | `Cache-Control` max-age |
| `EVATHUMBER_PHP_BINARY` | `PHP_BINARY` | PHP used for processing subprocess |

## Library usage

```php
use EvaThumber\Image\Pipeline;
use EvaThumber\Source\LocalSource;
use EvaThumber\Transformation\Parser;

$source = (new LocalSource('/path/to/images'))->resolve('photo');
(new Pipeline())->write($source, (new Parser())->parse('c_fill,w_300,h_300'), '/tmp/out.webp', 'webp');
```

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan level 8
docker compose up --build
```

## Compatibility matrix

| Area | Status |
| --- | --- |
| Resize/crop modes (`c_scale/fit/fill/crop/thumb/pad/limit`) | Supported |
| Dimensions, `ar`, `dpr`, compass gravity, NW-coordinate crop | Supported |
| Right-angle rotation, flips, grayscale, negate | Supported |
| Output formats jpg/png/webp/avif/gif, `f`, extension delivery, `f_auto` | Supported |
| `q_auto` | Not applicable (no equivalent algorithm; explicit rejection) |
| `g_auto`, face detection | Planned (requires real detection models) |
| Animated images | Planned |
| Overlays/layers, text, stylized effects (`r`, blur, vignette, …) | Planned |
| Remote/S3 sources | Planned (source abstraction ready) |
| Video, Upload/Admin APIs | Not applicable |

See [docs/migration-v1.md](docs/migration-v1.md) for moving from EvaThumber 1.x.

## License

BSD-3-Clause — see [LICENSE](LICENSE).
