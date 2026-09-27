<?php

declare(strict_types=1);

// Full-pixel image verification for the Docker acceptance suites.
//
// These suites orchestrate Docker from the host, but libvips is only guaranteed
// inside the image under test. A CI runner's host PHP has no libvips at all, so
// verification is done where the codec actually is: bytes are piped into `php`
// inside the container and the fully decoded geometry comes back. Returns
// [width, height] and throws unless libvips decoded every pixel.
//
// Usage: $oracle = require __DIR__ . '/image-oracle.php'; $oracle($container, $bytes);
require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Process\Process;

return static function (string $container, string $bytes, float $timeout = 120): array {
    $script = 'require "/app/vendor/autoload.php";'
        . '$image = \Jcupitt\Vips\Image::newFromBuffer(stream_get_contents(STDIN));'
        . 'echo $image->width, "x", $image->height;';
    $process = new Process(['docker', 'exec', '-i', $container, 'php', '-r', $script], input: $bytes, timeout: $timeout);
    $process->mustRun();
    $geometry = trim($process->getOutput());
    if (preg_match('/\A(\d+)x(\d+)\z/', $geometry, $match) !== 1) {
        throw new RuntimeException('Not a decodable image (oracle returned ' . var_export($geometry, true) . '): '
            . $process->getErrorOutput());
    }
    return [(int) $match[1], (int) $match[2]];
};
