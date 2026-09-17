# Migration from EvaThumber 1.x to 2.0

> 2026-09-17 首扫说明：保留本文的历史迁移映射；本次未重新审计 v1 历史代码。文中 Planned/Not planned 属历史陈述，未来路线图待维护者确认，不是交付承诺。当前实现以 [架构边界](architecture/boundaries.md) 为准，部署验收与文件追踪缺口见 [部署](operations/deploy.md)。下文“index.php 不再存在”指旧根目录入口；当前 HTTP 入口是 `public/index.php`。

EvaThumber 2 is a complete rewrite. The URL API, engine, configuration and deployment model have all changed. There is no compatibility mode.

## URL structure

| | 1.x | 2.0 |
| --- | --- | --- |
| Path | `/thumb/{configKey}/{id},params.ext` | `/[cloud_name]/image/upload/{chain}/{public_id}.ext` |
| Separator | `,` for everything | `/` between steps, `,` inside a step |
| Config keys | PHP config array (`config.local.php`) | Optional `cloud_name` in URL, env-based settings |

## Parameter mapping

| 1.x | 2.0 | Notes |
| --- | --- | --- |
| `c_fill`, `c_fit`, `c_scale` … | same `c_` values | Semantics now follow current Cloudinary docs (normative) |
| `f_gray` (filter) | `e_grayscale` | `f` is now output format only |
| `f_png`, `f_gif` (output format) | delivery extension or `f_png` | |
| `r_90` (rotate) | `a_90` | `r` now means rounded corners in Cloudinary syntax (not yet supported in 2.0) |
| `p_50` (percent) | `w_0.5` or `h_0.5` relative sizes | |
| `g_top`/`g_bottom`/`g_left`/`g_right` | `g_north`/`g_south`/`g_west`/`g_east` | Compass naming |
| `d_flickr`/`d_picasa`/`d_unsplash` dummy sources | removed | Local originals only; remote/S3 is planned |
| `l_` layer overlays, watermark | removed | Planned |
| QR code generation, face detection (OpenCV), ZIP archives, PNGOut optimization | removed | Not planned for 2.x core |

## Engines

1.x supported GD/Imagick/Gmagick via Imagine. 2.0 uses libvips exclusively for lazy, low-memory processing. No fallback engines.

## Deployment

1.x shipped an Apache `.htaccess` PHP 5 app with `config.default.php` / `config.local.php`. 2.0 ships a FrankenPHP Docker image configured by environment variables (see README). `index.php` and the config files no longer exist.

## Cache

1.x wrote derived files next to sources under a public `thumb/` directory. 2.0 uses a dedicated cache directory with atomic publication, capacity bounds and HTTP conditional support; it should not be web-served directly.

## Migration checklist

1. Replace `/thumb/{key}/{id},{params}.ext` URLs with `/image/upload/{steps}/{id}.ext`.
2. Translate removed features (layers, dummy providers, QR, face detect) to external tooling or wait for planned support.
3. Move originals into the source directory (read-only mount) and provision a writable cache volume.
4. Update deployment from mod_php/Apache to the provided Docker/Compose setup.
5. Update any client that relied on silent fallbacks — 2.0 returns explicit 4xx errors for unsupported parameters.
