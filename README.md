# EvaThumber 2

A self-hosted image transformation service.

Put your original images in a directory, start one Docker container, then resize, crop and convert them by changing the URL.

```text
/image/upload/c_fill,w_640,h_360/demo.jpg
```

No upload API. No database. No configuration file.

EvaThumber keeps your originals untouched and generates everything else on demand.

## See it

The demo image is 5184×3456. EvaThumber never emits anything larger than 4096 pixels on a side, so the picture below is the file as it sits in your images directory. It is not something you can fetch back through a transformation URL.

![Original image](docs/readme/demo.jpg)

Resize to 600px wide:

```text
/image/upload/w_600/demo.jpg
```

![Resize to 600px](docs/readme/w_600.jpg)

Crop it to a 600×300 image:

```text
/image/upload/c_fill,w_600,h_300/demo.jpg
```

![Fill 600x300](docs/readme/c_fill,w_600,h_300.jpg)

Turn it into a square:

```text
/image/upload/c_fill,w_400,h_400/demo.jpg
```

![Square crop](docs/readme/c_fill,w_400,h_400.jpg)

Keep the whole image and fit it inside 600×300:

```text
/image/upload/c_fit,w_600,h_300/demo.jpg
```

![Fit 600x300](docs/readme/c_fit,w_600,h_300.jpg)

Add a white canvas instead of cropping:

```text
/image/upload/c_pad,w_600,h_300,b_white/demo.jpg
```

![Pad 600x300](docs/readme/c_pad,w_600,h_300,b_white.jpg)

Convert to grayscale:

```text
/image/upload/w_600/e_grayscale/demo.jpg
```

![Grayscale](docs/readme/w_600,e_grayscale.jpg)

Convert to WebP with automatic quality:

```text
/image/upload/w_600/q_auto/f_webp/demo.jpg
```

![WebP auto quality](docs/readme/w_600,q_auto,f_webp.webp)

Transformations can be chained:

```text
/image/upload/c_fill,w_600,h_300/e_grayscale/q_80/f_webp/demo.jpg
```

![Transformation chain](docs/readme/c_fill,w_600,h_300_e_grayscale_q_80_f_webp.webp)

That is basically how EvaThumber works.

## Run it

Mount the directory containing your original images:

```bash
docker run \
  -p 8080:8080 \
  -v /path/to/your/images:/data/images:ro \
  docker.io/allovince/evathumber
```

If `/path/to/your/images/demo.jpg` exists, open:

```text
http://localhost:8080/image/upload/w_600/demo.jpg
```

The image is generated on the first request and cached afterwards.

The original directory is mounted read-only. EvaThumber never modifies your source images.

## Cache

Generated images are cached in `/data/cache`.

You do not have to configure it.

With the command above, the cache lives inside the container. Removing the container effectively clears all generated images. They will simply be generated again when requested.

If you want the cache to survive container replacement, mount it:

```bash
docker run \
  -p 8080:8080 \
  -v /path/to/your/images:/data/images:ro \
  -v evathumber-cache:/data/cache \
  docker.io/allovince/evathumber
```

The cache contains derived data only. It does not need to be backed up.

## URL

EvaThumber uses Cloudinary-style transformation URLs:

```text
/image/upload/{transformations}/{public_id}.{ext}
```

It also accepts the Cloudinary form with a cloud name:

```text
/{cloud_name}/image/upload/{transformations}/{public_id}.{ext}
```

Parameters inside one transformation are separated by commas:

```text
c_fill,w_600,h_400
```

Multiple transformations are separated by `/`:

```text
c_fill,w_600,h_400/e_grayscale/q_80/f_webp
```

So:

```text
/image/upload/c_fill,w_600,h_400/e_grayscale/q_80/f_webp/demo.jpg
```

means:

```text
demo.jpg
    ↓
fill 600×400
    ↓
grayscale
    ↓
quality 80
    ↓
WebP
```

## Transformations

### Resize

Resize by width:

```text
/image/upload/w_600/demo.jpg
```

Resize by height:

```text
/image/upload/h_400/demo.jpg
```

Relative size:

```text
/image/upload/w_0.5/demo.jpg
```

### Crop and fit

Scale:

```text
/image/upload/c_scale,w_600/demo.jpg
```

Fit inside a box without cropping:

```text
/image/upload/c_fit,w_600,h_300/demo.jpg
```

Fill a box and crop overflow:

```text
/image/upload/c_fill,w_600,h_300/demo.jpg
```

Crop:

```text
/image/upload/c_crop,w_600,h_400,g_center/demo.jpg
```

Thumbnail:

```text
/image/upload/c_thumb,w_400,h_400,g_center/demo.jpg
```

Pad:

```text
/image/upload/c_pad,w_600,h_300,b_white/demo.jpg
```

Limit an image without enlarging it:

```text
/image/upload/c_limit,w_1600,h_1600/demo.jpg
```

### Gravity

```text
/image/upload/c_fill,w_400,h_400,g_north/demo.jpg
/image/upload/c_fill,w_400,h_400,g_south/demo.jpg
/image/upload/c_fill,w_400,h_400,g_east/demo.jpg
/image/upload/c_fill,w_400,h_400,g_west/demo.jpg
```

Compass gravity is supported:

```text
center
north
north_east
east
south_east
south
south_west
west
north_west
```

### Aspect ratio

```text
/image/upload/c_fill,w_600,ar_16:9/demo.jpg
```

### DPR

```text
/image/upload/c_fill,w_300,h_200,dpr_2/demo.jpg
```

The resulting image is rendered at 600×400 pixels.

### Rotate and flip

```text
/image/upload/w_600/a_90/demo.jpg
/image/upload/w_600/a_180/demo.jpg
/image/upload/w_600/a_hflip/demo.jpg
/image/upload/w_600/a_vflip/demo.jpg
```

### Effects

```text
/image/upload/w_600/e_grayscale/demo.jpg
/image/upload/w_600/e_negate/demo.jpg
```

### Quality

Explicit quality:

```text
/image/upload/w_600/q_80/demo.jpg
```

Automatic quality:

```text
/image/upload/w_600/q_auto/demo.jpg
/image/upload/w_600/q_auto:best/demo.jpg
/image/upload/w_600/q_auto:good/demo.jpg
/image/upload/w_600/q_auto:eco/demo.jpg
/image/upload/w_600/q_auto:low/demo.jpg
```

`q_auto` is EvaThumber's local content-adaptive heuristic. It is not Cloudinary's proprietary perceptual algorithm.

### Output size limit

The result of every request must fit within 4096 pixels on each side and 16 megapixels in total. Rotation, flipping, effects and quality settings do not change the pixel count, so applying them on their own to a large original is rejected with `413 image_too_large`. Resize in the same request, as in every example above.

### Format

The URL extension can choose the output format:

```text
/image/upload/w_600/demo.webp
```

Or use `f_`:

```text
/image/upload/w_600/f_webp/demo.jpg
/image/upload/w_600/f_avif/demo.jpg
/image/upload/w_600/f_png/demo.jpg
```

Automatic format negotiation is also supported:

```text
/image/upload/w_600/f_auto/demo.jpg
```

EvaThumber uses the request's `Accept` header to choose the output format.

## Supported formats

Input:

```text
JPEG
PNG
WebP
AVIF
GIF (static only)
```

Output:

```text
JPEG
PNG
WebP
AVIF
GIF
```

## What EvaThumber does not do

EvaThumber 2 deliberately keeps its scope small.

It does not currently implement:

- remote or S3 image sources
- animated image processing
- SVG or PDF
- text and image overlays
- face detection
- automatic AI gravity
- rounded corners
- blur, sharpen and other stylized effects
- video transformation
- upload or administration APIs

Unsupported transformations fail explicitly instead of being silently approximated.

## Production behavior

EvaThumber 2 is built on PHP 8.5, FrankenPHP and libvips.

The Docker image:

- supports `linux/amd64` and `linux/arm64`
- runs as a non-root user
- keeps source images read-only
- publishes cached files atomically
- deduplicates concurrent generation of the same image
- uses bounded work queues under load
- returns `503` instead of allowing overload to corrupt images or exhaust the process
- supports `ETag`, `Last-Modified` and conditional `304` responses
- exposes `/healthz` and `/readyz`

The default configuration is intended to work without tuning.

If you need to know exactly how it behaves under load or process failure, see [`bench/`](bench/) and [`docs/`](docs/). The repository contains the benchmark and crash-recovery evidence used for the 2.0 release.

## Using it as a PHP library

The transformation engine can also be used without the HTTP service:

```php
use EvaThumber\Image\Pipeline;
use EvaThumber\Source\LocalSource;
use EvaThumber\Transformation\Parser;

$source = (new LocalSource('/path/to/images'))->resolve('demo');

(new Pipeline())->write(
    $source,
    (new Parser())->parse('c_fill,w_600,h_400'),
    '/tmp/demo.webp',
    'webp'
);
```

The library core does not depend on the HTTP cache or server layer.

## Development

```bash
composer install
composer test
composer analyse
```

See [`docs/`](docs/) for architecture, testing and release documentation.

## From EvaThumber 1.x

EvaThumber started in 2012 as a small PHP image thumbnail library.

Version 2 is a complete rewrite. The basic idea is still the same:

> change the URL, get the image you need.

The URL syntax, processing engine and deployment model have changed.

See [`docs/migration-v1.md`](docs/migration-v1.md).

## License

BSD-3-Clause. See [LICENSE](LICENSE).

### Demo image

`demo.jpg` uses **Lake Mountain Landscape** by Bonnie Moreland, released under CC0 / public domain.

Source: Wikimedia Commons.