# EvaThumber 2 architecture

PHP 8.5, Symfony 7.4 LTS, libvips via jcupitt/vips. BSD-3-Clause; existing Git history retained.

## Boundaries

- `Transformation\Parser::parse(string): Transformation` is independent of HTTP.
- Immutable `Transformation\Transformation` exposes `list<Step> $steps` and `canonical(): string`.
- Immutable `Transformation\Step` exposes `array<string,string> $parameters`, `get(string, ?string = null): ?string`.
- `Url\Parser::parse(string): ImageUrl` accepts the raw URL path (no Request dependency). `ImageUrl` exposes `publicId`, `?string format`, `Transformation transformation`, `?string cloudName`, `?string version`.
- `Source\SourceInterface::resolve(string $publicId): SourceImage`. Public ID excludes delivery extension; LocalSource matches exact extensionless file or unique supported extension. Ambiguity is an error, never arbitrary selection.
- `Source\SourceImage` exposes `string path`, `string identity`, `int modifiedAt`, `int bytes`, `string format`.
- `Image\Pipeline::write(SourceImage, Transformation, string $destination, string $format): void` constructs one lazy graph and writes directly to the destination; no PHP pixel buffers. Constructor accepts `Security\Limits`.
- `Image\Thumber::transform(SourceImage, Transformation, string $destination, ?string $format = null): void` is the Composer facade; no HTTP/runtime dependency.
- `Cache\DiskCache::remember(string $identity, string $format, callable $producer): CacheEntry` publishes atomically and uses bounded lock stripes; returns `path`, `etag`, `modifiedAt`, `hit`. Cache identity includes source revision, canonical transformation, negotiated format, encoder version/policy and configured limits.
- HTTP delivery uses Symfony HttpKernel, Routing, HttpFoundation and BinaryFileResponse. Request/image state never survives worker calls. Global libvips operation cache is disabled, concurrency bounded. Cache hits do not load libvips.

## Shared error and limit contracts

`Exception\ImageException` extends RuntimeException; constructor `(string $message, int $status = 400, string $error = 'invalid_transformation')`; readonly public status/error fields. Never include local paths in client errors.

`Security\Limits` readonly defaults: `maxSourceBytes=33554432`, `maxSourcePixels=40000000`, `maxSourceDimension=20000`, `maxOutputDimension=4096`, `maxOutputPixels=16000000`, `maxSteps=8`, `maxParameters=64`, `maxUrlLength=4096`. Validated at construction. Native processing is bounded by container memory/CPU and a hard HTTP subprocess deadline where needed; PHP execution timeout alone is not a native-code wall-clock timeout.

## Compatibility policy

The current Cloudinary Image Transformation URL reference is normative, not v1. No fallback for unsupported syntax, effects, gravity or combinations. `/` preserves action order; `,` groups qualifiers. Default resize is scale. Support integer/relative dimensions and aspect ratio, compass gravity, explicit coordinate crop, DPR, right-angle rotation/flips, selected effects, five raster formats. Explicitly document restricted combinations and reject them before processing. Auto quality is not silently aliased to a fixed quality: either provide a documented local adaptive algorithm or return unsupported. AI gravity is unsupported, not emulated with a different algorithm. Animated input is explicitly unsupported until frame semantics are implemented. Remote fetch, SVG/PDF and network loaders are unavailable.

Official reference: https://cloudinary.com/documentation/image_transformation_reference (consulted 2026-09-17).
