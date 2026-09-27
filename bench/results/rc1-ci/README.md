# RC1 native CI evidence

Run `36321362079` on `79ce709cfe0609100eae9ebb2b8628226b800c7d` (`push`), concluded `success`.
https://github.com/AlloVince/EvaThumber/actions/runs/36321362079

Window: 2026-09-27T13:08:27Z -> 2026-09-27T13:18:14Z

Both native runners execute the full suite as uid 33, PHPStan level 8, and all five
Docker acceptance suites against a production image built for that architecture.
The `image` job then builds linux/amd64 and linux/arm64 with buildx; it pushes only
on a `v*` tag, so this run published nothing.

| Job | Conclusion |
|---|---|
| test (linux/amd64, ubuntu-24.04) | success |
| test (linux/arm64, ubuntu-24.04-arm) | success |
| image | success |

## Steps

- `success` Set up job
- `success` Run actions/checkout@v4
- `success` Run shivammathur/setup-php@v2
- `success` Run composer install --no-interaction --prefer-dist
- `success` Build development image with native libvips
- `success` Run tests and analysis on actual architecture
- `success` Build production image
- `success` Verify readonly nonroot HTTP and shutdown
- `success` RC1 product acceptance on the documented two-flag invocation
- `success` Product acceptance across cache volume modes
- `success` Docker acceptance matrix
- `success` Crash recovery acceptance
- `success` Post Run actions/checkout@v4
- `success` Complete job
