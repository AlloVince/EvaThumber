# EvaThumber 2

Self-hosted image transformation service. Send Cloudinary-style transformation URLs; EvaThumber derives resized, cropped and re-encoded images from your local originals with libvips, caches them on disk and serves them with HTTP caching. PHP 8.5 + FrankenPHP in a single nonroot Docker image.

[中文文档](README.zh-CN.md)

## Quick start

```bash
docker run -p 8080:8080 -v /path/to/your/images:/data/images:ro ghcr.io/allovince/evathumber
```

Then request a transformation:

```bash
curl -o out.webp 'http://localhost:8080/image/upload/c_fill,w_300,h_300/f_webp/your-image.jpg'
```

That is the whole setup. The only thing you configure is your image directory, mounted read-only at `/data/images`. Everything else — workers, cache, timeouts, limits — has working defaults.

### Cache

Generated images go to `/data/cache` inside the container. By default nothing is mounted there, so the cache is ephemeral: it disappears with the container and never needs backup. To keep derived images across restarts, add a volume:

```bash
docker run -p 8080:8080 \
  -v /path/to/your/images:/data/images:ro \
  -v evathumber-cache:/data/cache \
  ghcr.io/allovince/evathumber
```

The cache is derived data: deleting it never affects your originals, and the service regenerates everything on demand. `/healthz` is liveness; `/readyz` is readiness (fails with 503 while the cache is not writable).

## URL syntax

```
/image/upload/{transformation_chain}/{public_id}.{ext}
/{cloud_name}/image/upload/{transformation_chain}/{public_id}.{ext}
```

- Chain steps with `/`, qualifiers with `,` (e.g. `c_fill,w_300,h_300/q_80`).
- The delivery extension (`.webp`, `.jpg`, …) sets the output format; `f_` inside the chain overrides it; `f_auto` negotiates via `Accept` (adds `Vary: Accept`; webp wins ties).
- A `v123` version segment changes cache identity, not source resolution. It is not a historical snapshot and does not enable `immutable` caching.

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

Supported sources/formats: JPEG, PNG, WebP, AVIF, GIF (static frames only).

### Explicitly unsupported (rejected with HTTP 400/404/415, never silently approximated)

- `q_auto:sensitive`, unknown quality tiers, and `q_auto` with PNG/GIF output. Supported auto tiers use a [local heuristic](docs/components/Image/auto-quality.md), not Cloudinary's perceptual algorithm.
- `g_auto` / `g_face` — AI gravity requires Cloudinary's models; not emulated.
- Animated inputs (multi-frame GIF/WebP) — rejected rather than silently flattening.
- Remote/S3 fetch, SVG/PDF sources, layer overlays, text, rounded corners (`r`), blur/sharpen, `lfill`/`lpad`/`mfit`/`mpad`.
- Query parameters other than scalar `_a`/`_i` analytics (ignored for image identity); GET/HEAD only.

## Caching and HTTP behaviour

- Derived images are cached on disk, keyed by source revision + canonical transformation + negotiated format + policy version. Hits bypass libvips entirely.
- Responses carry `ETag`, `Last-Modified`, `Cache-Control: public, max-age=3600`; conditional `If-None-Match` returns `304`.
- Cold requests are admission-bounded: when the service is saturated, misses are rejected fast with `503` (`Retry-After: 1`) instead of piling up. Same-key misses share one transformation. Processing timeouts return `504`.
- Capacity is enforced by oldest-write eviction of idle entries; an oversized product returns `507 cache_full`.
- Under overload you will see `503`, never wrong images. The service queues what it can, rejects the rest boundedly, and recovers on its own when load drops.

## Performance

Measured on the production Docker image (arm64, 2 CPU / 512 MiB limit, default 2 transform workers) against local fixtures, 8-second windows per scenario. Full raw evidence: [`bench/results/rc1-http-full/`](bench/results/rc1-http-full/).

| Scenario | Concurrency | Success rate | Throughput (200s) | p50 / p95 / p99 ms |
| --- | ---: | ---: | ---: | ---: |
| Hot cache | 16 | 100% (37,740/37,740) | ~4,700/s | 3.3 / 5.7 / 7.1 |
| Same-key cold | 16 | 98.7% (6,216/6,301) | ~775/s | 14.8 / 62.9 / 75.3 |
| Different-key cold | 4 | 100% (204/204) | ~25/s | 161 / 173 / 182 |
| Different-key cold | 16 | 2% (68/3,417), rest bounded 503 | ~8/s | 804 / 915 / 1,613 |
| Mixed | 16 | 15.7% (868/5,556), rest bounded 503 | ~107/s | 2.8 / 612 / 1,134 |

Notes:

- Same-key cold ran exactly **one** transformation at every concurrency (2–16); concurrent waiters reused it.
- All 503s were `cache_admission_full` / `processor_busy` (one `queue_timeout`) — the designed bounded rejection. Health probes stayed 200 throughout, every container exited 0 with no OOM, and later scenarios recovered immediately.
- Cold-miss throughput is intentionally small (2 workers, ~70–80 ms per derivation). Raise `EVATHUMBER_POOL_SIZE` only if you need more parallel derivations.

## Library usage (Composer)

```php
use EvaThumber\Image\Pipeline;
use EvaThumber\Source\LocalSource;
use EvaThumber\Transformation\Parser;

$source = (new LocalSource('/path/to/images'))->resolve('photo');
(new Pipeline())->write($source, (new Parser())->parse('c_fill,w_300,h_300'), '/tmp/out.webp', 'webp');
```

The library core is independent of the HTTP layer (no HTTP cache, admission or negotiation there).

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan level 8
```

Docker acceptance suites (require a Docker daemon and the fixtures in `upload/`):

```bash
docker build --platform linux/arm64 --target production -t evathumber:rc1 .
php tests/container-smoke.php    evathumber:rc1 linux/arm64
php tests/rc1-acceptance.php    evathumber:rc1 linux/arm64
php tests/product-acceptance.php evathumber:rc1 linux/arm64
php tests/docker-acceptance.php  evathumber:rc1 linux/arm64
php tests/crash-recovery.php    evathumber:rc1 linux/arm64
```

Every suite runs the image exactly as the Quick Start does — only `-p` and the read-only image mount, no cache volume, no tuning flags. `crash-recovery.php` additionally kills a busy worker, the container and the pool supervisor mid-encode, then proves every recovered product is byte-identical to one from a container that never crashed.

Architecture, acceptance status and release gates live in [`docs/`](docs/index.md).

## Compatibility matrix

| Area | Status |
| --- | --- |
| Resize/crop modes (`c_scale/fit/fill/crop/thumb/pad/limit`) | Supported |
| Dimensions, `ar`, `dpr`, compass gravity, NW-coordinate crop | Supported |
| Right-angle rotation, flips, grayscale, negate | Supported |
| Output formats jpg/png/webp/avif/gif, `f`, extension delivery, `f_auto` | Supported |
| `q_auto[:best\|good\|eco\|low]` | Local content/format-adaptive Q for JPEG/WebP/AVIF; not Cloudinary-equivalent |
| `g_auto`, face detection | Not supported (requires real detection models) |
| Animated images | Not supported |
| Overlays/layers, text, stylized effects (`r`, blur, vignette, …) | Not supported |
| Remote/S3 sources | Not supported |
| Video, Upload/Admin APIs | Not applicable |

See [docs/migration-v1.md](docs/migration-v1.md) for moving from EvaThumber 1.x.

## License

BSD-3-Clause — see [LICENSE](LICENSE).
