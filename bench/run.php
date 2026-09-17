<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use EvaThumber\Http\Settings;
use EvaThumber\Image\IsolatedProcessor;
use EvaThumber\Transformation\Parser;
use Jcupitt\Vips\Image;

$mode = $argv[1] ?? 'isolated';
$count = filter_var($argv[2] ?? '20', FILTER_VALIDATE_INT);
if (!in_array($mode, ['isolated', 'persistent'], true) || $count === false || $count < 2 || $count > 1000) {
    fwrite(STDERR, "Usage: php bench/run.php isolated|persistent [samples:2..1000]\n");
    exit(2);
}
$root = sys_get_temp_dir() . '/eva-bench-' . bin2hex(random_bytes(8));
mkdir($root);
$process = null;
$pipes = [];
try {
    // Deterministic synthetic RGB stripes, not a representative photographic corpus.
    $xy = Image::xyz(1600, 1000);
    $x = $xy->extract_band(0);
    $y = $xy->extract_band(1);
    $x->bandjoin([$y, $x->add($y)])->cast('uchar')->jpegsave($root . '/fixture.jpg', ['Q' => 90]);
    $expression = 'c_fill,w_400,h_300/f_webp,q_80';
    $transform = (new Parser())->parse($expression);
    $processor = new IsolatedProcessor(new Settings($root, $root));
    $job = json_encode(['root' => $root, 'publicId' => 'fixture', 'transformation' => $expression,
        'destination' => $root . '/output.webp', 'format' => 'webp'], JSON_THROW_ON_ERROR) . "\n";
    $before = getrusage(1);
    $wallStart = hrtime(true);
    if ($mode === 'persistent') {
        $process = proc_open([PHP_BINARY, '-d', 'ffi.enable=true', __DIR__ . '/worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $root . '/stderr.log', 'a']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start benchmark worker');
        }
        stream_set_timeout($pipes[1], 15);
    }
    $samples = [];
    $digest = null;
    for ($i = 0; $i < $count + 2; ++$i) {
        $start = hrtime(true);
        if ($mode === 'isolated') {
            $processor->write('fixture', $transform, $root . '/output.webp', 'webp');
        } else {
            if (fwrite($pipes[0], $job) !== strlen($job) || !fflush($pipes[0])) {
                throw new RuntimeException('Worker input failed');
            }
            $line = fgets($pipes[1]);
            if ($line === false || (json_decode($line, true, 32, JSON_THROW_ON_ERROR)['ok'] ?? false) !== true) {
                throw new RuntimeException('Worker failed or timed out');
            }
        }
        $elapsed = (hrtime(true) - $start) / 1e6;
        $current = hash_file('sha256', $root . '/output.webp');
        if ($current === false || ($digest !== null && $current !== $digest)) {
            throw new RuntimeException('Inconsistent output');
        }
        $digest = $current;
        if ($i >= 2) {
            $samples[] = $elapsed;
        }
    }
    if (is_resource($process)) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        $pipes = [];
        $exitCode = proc_close($process);
        $process = null;
        if ($exitCode !== 0) {
            throw new RuntimeException('Worker exit failed');
        }
    }
    $wall = (hrtime(true) - $wallStart) / 1e9;
    $after = getrusage(1);
    $cpu = static fn (array $usage): float => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
        + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
    sort($samples);
    $percentile = static fn (float $p): float => $samples[(int) ceil(count($samples) * $p) - 1];
    echo json_encode([
        'mode' => $mode, 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
        'fixture' => 'synthetic RGB stripes 1600x1000 JPEG', 'transform' => $expression,
        'concurrency' => 1, 'samples' => $count, 'warmup' => 2,
        'p50_ms' => $percentile(0.50), 'p95_ms' => $percentile(0.95), 'p99_ms' => $percentile(0.99),
        'serial_jobs_per_second' => $count / (array_sum($samples) / 1000),
        'child_cpu_seconds_including_warmup' => $cpu($after) - $cpu($before),
        'wall_seconds_including_warmup' => $wall,
        'child_peak_rss_mib' => $after['ru_maxrss'] / (PHP_OS_FAMILY === 'Darwin' ? 1048576 : 1024),
        'output_sha256' => $digest,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process, 9);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    foreach (new DirectoryIterator($root) as $file) {
        if (!$file->isDot()) {
            unlink($file->getPathname());
        }
    }
    rmdir($root);
}
