<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use EvaThumber\Image\AutoQuality;

// q_auto execution benchmark: full-output copyMemory vs rebuild-lazy.
// Usage: php -d ffi.enable=true bench/quality-memory.php [image] [iterations] [expression]
// Default image: upload/blend.png (repo fixture, 300x200 RGBA PNG).
// Each mode runs in a fresh PHP process; ru_maxrss is the process peak.
$image = $argv[1] ?? dirname(__DIR__) . '/upload/blend.png';
$iterations = max(3, (int) ($argv[2] ?? 7));
$expression = $argv[3] ?? 'q_auto';
if (!is_file($image)) {
    fwrite(STDERR, "Image not found: {$image}\n");
    exit(1);
}
$root = sys_get_temp_dir() . '/eva-qmem-' . bin2hex(random_bytes(6));
mkdir($root);
try {
    $rows = [];
    foreach (['jpg', 'webp', 'avif'] as $format) {
        foreach (['copy-memory', 'rebuild-lazy'] as $mode) {
            $samples = [];
            for ($i = 0; $i < $iterations; ++$i) {
                $output = $root . '/out-' . bin2hex(random_bytes(4));
                $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                $process = proc_open([PHP_BINARY, '-d', 'ffi.enable=true', dirname(__DIR__) . '/bench/quality-memory-worker.php', $image, $expression, $format, $mode, $output], $descriptor, $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException('Worker failed to start');
                }
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                if (proc_close($process) !== 0 || !json_validate($stdout)) {
                    fwrite(STDERR, "Worker failed ({$format}/{$mode}): {$stderr}{$stdout}\n");
                    exit(1);
                }
                $sample = json_decode($stdout, true);
                if ($i > 0) {
                    $samples[] = $sample;
                }
                @unlink($output);
            }
            $ms = array_column($samples, 'ms');
            $rss = array_column($samples, 'peak_rss_mib');
            sort($ms);
            sort($rss);
            $first = $samples[0];
            $rows[] = [
                'format' => $format, 'mode' => $mode,
                'q' => $first['q'], 'bytes' => $first['bytes'], 'sha256' => $first['sha256'],
                'rgb_white_matte_mae' => $first['rgb_white_matte_mae'], 'alpha' => $first['alpha'],
                'width' => $first['width'], 'height' => $first['height'],
                'p50_ms' => $ms[intdiv(count($ms), 2)],
                'max_ms' => max($ms),
                'p50_peak_rss_mib' => $rss[intdiv(count($rss), 2)],
                'max_peak_rss_mib' => max($rss),
                'samples' => count($samples), 'raw_samples' => $samples,
            ];
        }
        $baseline = $rows[count($rows) - 2];
        $rebuilt = $rows[count($rows) - 1];
        foreach (array_merge($baseline['raw_samples'], $rebuilt['raw_samples']) as $sample) {
            foreach (['q', 'bytes', 'sha256', 'rgb_white_matte_mae', 'alpha', 'width', 'height'] as $field) {
                if ($sample[$field] !== $baseline[$field]) {
                    throw new RuntimeException("Execution strategies differ: {$format}/{$field}");
                }
            }
        }
    }
    echo json_encode([
        'php' => PHP_VERSION, 'os' => php_uname('s') . ' ' . php_uname('m'), 'libvips' => \Jcupitt\Vips\Config::version(),
        'policy' => AutoQuality::POLICY, 'image' => realpath($image), 'image_sha256' => hash_file('sha256', $image),
        'expression' => $expression, 'iterations' => $iterations - 1, 'discarded_processes_per_mode' => 1,
        'equivalent' => true,
        'note' => 'Fresh process per sample; ru_maxrss includes bootstrap, snapshot and native codecs; timer excludes bootstrap. Fixed mode order. MAE on white-matted RGB is not perceptual quality. Quality check after RSS capture. Fixture origin is not inferred from its filename.',
        'results' => $rows,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} finally {
    foreach (new DirectoryIterator($root) as $file) {
        if (!$file->isDot()) {
            @unlink($file->getPathname());
        }
    }
    @rmdir($root);
}
