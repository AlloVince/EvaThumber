<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use EvaThumber\Image\AutoQuality;
use EvaThumber\Image\Pipeline;
use EvaThumber\Source\LocalSource;
use EvaThumber\Transformation\Parser;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Image;

// Private graph access is deliberate: compare execution, not a second pixel algorithm.
[$script, $input, $expression, $format, $mode, $output] = $argv;
Config::cacheSetMax(0);
Config::concurrencySet(2);
$pipeline = new Pipeline();
$prepare = new ReflectionMethod(Pipeline::class, 'prepare');
$transform = (new Parser())->parse($expression);
$tier = $transform->get('q') ?? '';
if (!str_starts_with($tier, 'auto') || $transform->get('f') !== null) {
    throw new InvalidArgumentException('Use q_auto[:tier], with no f parameter; formats are benchmarked separately.');
}
$loaders = ['jpg' => 'jpegload_buffer', 'png' => 'pngload_buffer', 'webp' => 'webpload_buffer', 'avif' => 'heifload_buffer', 'gif' => 'gifload_buffer'];
$start = hrtime(true);
$source = (new LocalSource(dirname($input)))->resolve(basename($input));
$build = static fn (): Image => $prepare->invoke($pipeline, $source->snapshot->content, $loaders[$source->format], $transform, $format);
if ($mode === 'copy-memory') {
    $image = $build()->copyMemory();
    $q = (new AutoQuality())->select($image, $tier, $format);
    match ($format) {
        'jpg' => $image->jpegsave($output, ['Q' => $q, 'strip' => true]),
        'webp' => $image->webpsave($output, ['Q' => $q, 'strip' => true]),
        'avif' => $image->heifsave($output, ['Q' => $q, 'compression' => 'av1', 'effort' => 3, 'strip' => true]),
    };
    unset($image);
} elseif ($mode === 'rebuild-lazy') {
    $pipeline->write($source, $transform, $output, $format);
} else {
    throw new InvalidArgumentException('Unknown execution mode');
}
$elapsed = (hrtime(true) - $start) / 1e6;
$rss = getrusage()['ru_maxrss'];
$peak = memory_get_peak_usage(true);
// Quality verification is AFTER the RSS/time capture: do not count reference pixels.
$reference = $build()->copyMemory();
$q = (new AutoQuality())->select($reference, $tier, $format);
$decoded = Image::newFromFile($output)->colourspace('srgb');
$alpha = $decoded->hasAlpha();
if ($reference->hasAlpha()) { $reference = $reference->flatten(['background' => [255, 255, 255]]); }
if ($decoded->hasAlpha()) { $decoded = $decoded->flatten(['background' => [255, 255, 255]]); }
echo json_encode([
    'ms' => $elapsed,
    'peak_rss_mib' => $rss / (PHP_OS_FAMILY === 'Darwin' ? 1048576 : 1024),
    'php_peak_mib' => $peak / 1048576,
    'q' => $q, 'bytes' => filesize($output), 'sha256' => hash_file('sha256', $output),
    'rgb_white_matte_mae' => $reference->subtract($decoded)->abs()->avg(),
    'alpha' => $alpha, 'width' => $decoded->width, 'height' => $decoded->height,
], JSON_THROW_ON_ERROR), "\n";
