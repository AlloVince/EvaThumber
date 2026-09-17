# EvaThumber 2

Cloudinary-compatible, self-hosted image transformation service for PHP. Send Cloudinary-style transformation URLs; EvaThumber derives resized, cropped and re-encoded images from your local originals with libvips, caches them on disk and serves them with proper HTTP caching.

[中文文档](README.zh-CN.md)

- PHP 8.5, strict types, Symfony 7.4 LTS HTTP layer
- libvips (via `jcupitt/vips`) for lazy, low-memory processing
- FrankenPHP worker-mode Docker image; local amd64/arm64 acceptance passed, [release gates remain](docs/progress.md)
- Independent Composer library core (`EvaThumber\Image\Thumber`) with a thin HTTP layer on top
- Explicit failures for unsupported parameters — no silent approximations

## Quick start (Docker)

```bash
mkdir -p data/images && cp your-image.jpg data/images/
docker compose up --build
curl -o out.webp 'http://localhost:8081/image/upload/c_fill,w_300,h_300,f_webp/your-image.jpg'
```

Set `EVATHUMBER_PORT` to change the host port. Sources are mounted read-only (`./data/images:/data/images:ro`); the cache and configured temporary filesystems are writable. The container runs nonroot with all capabilities dropped.

## URL syntax

```
/{cloud_name}/image/upload/{transformation_chain}/{public_id}.{ext}
/image/upload/{transformation_chain}/{public_id}.{ext}
```

- Chain steps with `/`, qualifiers with `,` (e.g. `c_fill,w_300,h_300/q_80`).
- The optional delivery extension (`.webp`, `.jpg`, …) sets the output format; `f_` inside a transformation overrides it; `f_auto` negotiates via `Accept` (adds `Vary: Accept`).
- A `v123` version segment changes cache identity, not source resolution: it is not a historical snapshot and does not enable `immutable` caching.

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
| `q` | 1–100, `auto[:best\|good\|eco\|low]` | Auto: local content-adaptive heuristic, JPEG/WebP/AVIF only; default `good` |
| `f` | `jpg`, `png`, `webp`, `avif`, `gif`, `auto` | `auto` = Accept negotiation |
| `b` | `rgb:RRGGBB` or `white`/`black`/`red`/`green`/`blue`/`transparent` | Pad background |
| `e` | `grayscale`, `negate` | |

Supported source/output formats: JPEG, PNG, WebP, AVIF, GIF (static frames only).

### Explicitly unsupported (rejected with HTTP 400)

- `q_auto:sensitive`, unknown quality tiers, and `q_auto` with PNG/GIF output. Supported auto tiers use a [local heuristic](docs/components/Image/auto-quality.md), not Cloudinary's perceptual algorithm; no Save-Data tier switching or visual-equivalence guarantee.
- `g_auto` / `g_face` — AI gravity requires Cloudinary's models; not emulated.
- Animated inputs (multi-frame GIF/WebP) — rejected rather than silently flattening.
- Remote fetch, SVG/PDF sources, layer overlays, text, rounded corners (`r`), blur/sharpen effects, `lfill`/`lpad`/`mfit`/`mpad`.
- Query parameters other than scalar `_a`/`_i` analytics (ignored for image identity); GET/HEAD only.

## Caching and HTTP

- Derived images are cached on disk, keyed by source revision + canonical transformation + negotiated format + policy version. Hits bypass libvips entirely.
- Responses carry `ETag`, `Last-Modified`, `Cache-Control: public, max-age=...`; conditional `If-None-Match` returns `304`.
- Each cache root has a fixed 8 cross-process admission slots, **including the single producer**. Hits bypass slots and the global publish lock; a miss finding all slots occupied immediately returns `503 processor_busy` (HTTP already supplies `Retry-After: 1`). Admitted misses wait/recheck for at most 250ms on the global publish lock, reusing a published result or returning the same busy error. This bounds cache-layer admission only, not the HTTP server queue; it is not per-key single-flight, and the global producer bottleneck remains.
- Process exit/SIGKILL releases admission slots through OS file locks. Never delete `.admission-0.lock` through `.admission-7.lock` or `.publish.lock` while any instance is running; existing lock files do not indicate stale occupancy. See the [admission decision](docs/architecture/adr/0001-cache-admission.md).
- Capacity is enforced by oldest-write eviction of idle entries; response leases protect files being sent. An oversized product returns `507 cache_full`; insufficient reclaimable capacity returns `503 cache_busy`.
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
| `q_auto[:best\|good\|eco\|low]` | Local content/format-adaptive Q for JPEG/WebP/AVIF; not Cloudinary-equivalent |
| `g_auto`, face detection | Planned (requires real detection models) |
| Animated images | Planned |
| Overlays/layers, text, stylized effects (`r`, blur, vignette, …) | Planned |
| Remote/S3 sources | Planned (source abstraction ready) |
| Video, Upload/Admin APIs | Not applicable |

See [docs/migration-v1.md](docs/migration-v1.md) for moving from EvaThumber 1.x.

## License

BSD-3-Clause — see [LICENSE](LICENSE).
