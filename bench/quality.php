<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use EvaThumber\Image\AutoQuality;
use EvaThumber\Image\Pipeline;
use EvaThumber\Source\LocalSource;
use EvaThumber\Transformation\Parser;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Image;

// Synthetic regression measurements, not a perceptual or photographic benchmark.
Config::cacheSetMax(0);
Config::concurrencySet(2);
$root = sys_get_temp_dir() . '/eva-quality-bench-' . bin2hex(random_bytes(8));
mkdir($root);
try {
    $xy = Image::xyz(640, 400);
    $x = $xy->extract_band(0);
    $y = $xy->extract_band(1);
    $edge = $x->divide(8)->floor()->remainder(2)->multiply(255);
    $fixtures = [
        'flat' => Image::black(640, 400, ['bands' => 3])->newFromImage([128, 128, 128]),
        'stripes' => $x->bandjoin([$y, $x->add($y)])->cast('uchar')->copy(['interpretation' => 'srgb']),
        'edges' => $edge->bandjoin([$edge, $edge])->cast('uchar')->copy(['interpretation' => 'srgb']),
    ];
    $rows = [];
    foreach ($fixtures as $name => $fixture) {
        $fixture->pngsave($root . '/' . $name . '.png');
        $source = (new LocalSource($root))->resolve($name);
        $reference = Image::pngload($source->path)->colourspace('srgb')->copyMemory();
        foreach (['jpg', 'webp', 'avif'] as $format) {
            foreach (['80', 'auto:best', 'auto:good', 'auto:eco', 'auto:low'] as $quality) {
                $transform = (new Parser())->parse('q_' . $quality);
                $samples = [];
                // One warmup, ten samples; no disk cache, current process only.
                for ($i = 0; $i < 11; ++$i) {
                    $start = hrtime(true);
                    (new Pipeline())->write($source, $transform, $root . '/output', $format);
                    if ($i > 0) {
                        $samples[] = (hrtime(true) - $start) / 1e6;
                    }
                }
                sort($samples);
                $decoded = Image::newFromFile($root . '/output')->colourspace('srgb');
                clearstatcache(true, $root . '/output');
                $rows[] = [
                    'fixture' => $name, 'format' => $format, 'quality' => $quality,
                    'selected_q' => str_starts_with($quality, 'auto')
                        ? (new AutoQuality())->select($reference, $quality, $format) : (int) $quality,
                    'bytes' => filesize($root . '/output'),
                    'rgb_mae_0_255' => $reference->subtract($decoded)->abs()->avg(),
                    'p50_ms' => $samples[4], 'p95_ms' => $samples[9],
                ];
                unset($decoded);
            }
        }
    }
    echo json_encode([
        'php' => PHP_VERSION, 'architecture' => php_uname('m'), 'policy' => AutoQuality::POLICY,
        'width' => 640, 'height' => 400, 'samples' => 10, 'warmup' => 1,
        'note' => 'Synthetic fixtures; RGB MAE is not perceptual quality. In-process, no HTTP or cache.',
        'results' => $rows,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} finally {
    foreach (new DirectoryIterator($root) as $file) {
        if (!$file->isDot()) {
            unlink($file->getPathname());
        }
    }
    rmdir($root);
}
