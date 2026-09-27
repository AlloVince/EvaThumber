<?php

declare(strict_types=1);

/**
 * First-time-user product acceptance for the documented production image.
 * Runs the exact `docker run -p 8080:8080 -v <dir>:/data/images:ro IMAGE`
 * from the README Quick Start, with no extra flags, then drives real HTTP.
 *
 * php tests/docker-acceptance.php IMAGE [PLATFORM]
 *
 * Proves: /healthz, /readyz, nonroot, read-only originals, writable cache,
 * the documented transformation matrix, cache MISS->HIT, conditional GET,
 * docker restart, graceful stop/start, and an optional persistent cache
 * volume reused by a second container. It does not claim throughput.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$image = $argv[1] ?? throw new InvalidArgumentException('Usage: php tests/docker-acceptance.php IMAGE [PLATFORM]');
$platform = $argv[2] ?? 'linux/arm64';
$tag = 'eva-accept-' . bin2hex(random_bytes(5));
$volume = 'eva-accept-cache-' . bin2hex(random_bytes(5));
$source = sys_get_temp_dir() . '/eva-accept-src-' . bin2hex(random_bytes(5));
$evidence = [];
$failures = [];
$checks = 0;

$docker = static function (array $args, bool $allowFailure = false) use (&$evidence): string {
    $process = new Process(array_merge(['docker'], $args), timeout: 120);
    $process->run();
    $evidence[] = ['docker' => $args, 'code' => $process->getExitCode()];
    if (!$allowFailure && !$process->isSuccessful()) {
        throw new RuntimeException('docker ' . implode(' ', $args) . ': ' . trim($process->getErrorOutput() ?: $process->getOutput()));
    }
    return trim($process->getOutput());
};
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    ++$checks;
    if (!$condition) {
        $failures[] = $message;
    }
};
/** Format from magic bytes: the container must not be trusted about Content-Type alone. */
$sniff = static function (string $body): string {
    return match (true) {
        str_starts_with($body, "\xFF\xD8\xFF") => 'jpg',
        str_starts_with($body, "\x89PNG\r\n\x1A\n") => 'png',
        str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP' => 'webp',
        substr($body, 4, 4) === 'ftyp' && in_array(substr($body, 8, 4), ['avif', 'avis'], true) => 'avif',
        str_starts_with($body, 'GIF8') => 'gif',
        default => 'unknown',
    };
};
$get = static function (string $base, string $path, array $headers = [], string $method = 'GET', float $timeout = 30) use ($sniff): array {
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
        'timeout' => $timeout, 'ignore_errors' => true]]);
    error_clear_last();
    $stream = @fopen($base . $path, 'rb', false, $context);
    if ($stream === false) {
        return ['status' => 0, 'body' => '', 'error' => error_get_last()['message'] ?? 'connect failed'];
    }
    try {
        $meta = stream_get_meta_data($stream);
        $body = (string) stream_get_contents($stream);
        $result = ['status' => (int) explode(' ', $meta['wrapper_data'][0])[1], 'body' => $body, 'format' => $sniff($body)];
        foreach (array_slice($meta['wrapper_data'], 1) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $result[strtolower($key)] = trim($value);
            }
        }
        return $result;
    } finally {
        fclose($stream);
    }
};
/** Readiness is the documented gate; /healthz must not depend on it. */
$await = static function (string $base, float $budget = 30.0) use ($get): array {
    $started = hrtime(true);
    $deadline = $started + (int) ($budget * 1e9);
    $attempts = 0;
    do {
        $probe = $get($base, '/readyz', timeout: 1.0);
        if ($probe['status'] === 200) {
            return ['ms' => round((hrtime(true) - $started) / 1e6, 3), 'attempts' => $attempts, 'body' => $probe['body']];
        }
        ++$attempts;
        usleep(100_000);
    } while (hrtime(true) < $deadline);
    throw new RuntimeException('/readyz never became ready: ' . json_encode($probe, JSON_UNESCAPED_SLASHES));
};
$remove = static function (string $name) use ($docker): void {
    $docker(['rm', '-f', $name], allowFailure: true);
};
$run = static function (string $name, array $mounts) use ($docker, $platform, $image): void {
    $docker(['run', '-d', '--name', $name, '--platform', $platform,
        '-p', '127.0.0.1::8080', ...$mounts, $image]);
    $docker(['start', $name]);
};

mkdir($source, 0700, true);
foreach (['demo.jpg', 'face.jpg', 'blend.png'] as $fixture) {
    copy($root . '/upload/' . $fixture, $source . '/' . $fixture);
}
$base = null;
try {
    // ---- 1. The documented command, nothing else. ---------------------------------
    $run('eva-accept-a', ['-v', $source . ':/data/images:ro']);
    $base = 'http://' . $docker(['port', 'eva-accept-a', '8080']);
    $ready = $await($base);
    $health = $get($base, '/healthz');
    $check($health['status'] === 200, '/healthz must answer 200');
    $check(str_contains(strtolower($health['cache-control'] ?? ''), 'no-store'), '/healthz must be no-store');
    $check(json_decode($ready['body'], true) === ['status' => 'ready'], '/readyz must report ready: ' . $ready['body']);
    $check($docker(['exec', 'eva-accept-a', 'id', '-u']) !== '0', 'container must not run as root');
    $write = new Process(['docker', 'exec', 'eva-accept-a', 'sh', '-c', 'touch /data/images/probe'], timeout: 20);
    $write->run();
    $check(!$write->isSuccessful(), '/data/images must be read-only');
    $docker(['exec', 'eva-accept-a', 'sh', '-c', 'touch /data/cache/probe && rm /data/cache/probe']);
    $cached = static function () use ($docker): int {
        return (int) trim($docker(['exec', 'eva-accept-a', 'sh', '-c', 'ls /data/cache | wc -l']));
    };

    // ---- 2. Documented transformation matrix over real HTTP. ----------------------
    // [path, accept, expected format, expected width, expected height|null]
    $matrix = [
        ['/image/upload/w_120/demo', [], 'jpg', 120, 80],
        ['/image/upload/w_120/f_webp/demo', [], 'webp', 120, 80],
        ['/image/upload/w_120/demo.webp', [], 'webp', 120, 80],
        ['/image/upload/w_120/f_avif/demo', [], 'avif', 120, 80],
        ['/image/upload/h_64/demo', [], 'jpg', 96, 64],
        ['/image/upload/c_fill,w_100,h_100,g_north_east/demo', [], 'jpg', 100, 100],
        ['/image/upload/c_crop,g_north_west,x_5,y_5,w_80,h_60/demo', [], 'jpg', 80, 60],
        ['/image/upload/c_thumb,g_south,w_80,h_80/f_webp/demo', [], 'webp', 80, 80],
        ['/image/upload/c_pad,w_100,h_60,b_white/demo', [], 'jpg', 100, 60],
        ['/image/upload/c_limit,w_40,h_40/demo', [], 'jpg', 40, 27],
        ['/image/upload/ar_16:9,w_160/demo', [], 'jpg', 160, 90],
        ['/image/upload/c_scale,w_0.5/demo', [], 'jpg', 150, 100],
        ['/image/upload/dpr_2.0,w_50/f_webp/demo', [], 'webp', 100, 66],
        ['/image/upload/e_grayscale/demo', [], 'jpg', 300, 200],
        ['/image/upload/a_90/demo', [], 'jpg', 200, 300],
        ['/image/upload/a_hflip/demo', [], 'jpg', 300, 200],
        ['/image/upload/c_fill,w_100,h_100/q_40/demo', [], 'jpg', 100, 100],
        ['/image/upload/c_fill,w_100,h_100/q_auto/demo', [], 'jpg', 100, 100],
        ['/image/upload/c_fill,w_100,h_100/q_auto:best/f_avif/demo', [], 'avif', 100, 100],
        ['/image/upload/c_fill,w_100,h_100/q_auto:eco/f_webp/demo', [], 'webp', 100, 100],
        ['/image/upload/w_60/blend.png', [], 'png', 60, 40],
        ['/image/upload/w_60/f_webp/blend.png', [], 'webp', 60, 40],
        ['/image/upload/w_60/f_auto/blend', ['Accept: image/webp,image/*'], 'webp', 60, 40],
        ['/image/upload/w_60/f_auto/blend', ['Accept: image/avif,image/*'], 'avif', 60, 40],
        ['/image/upload/w_60/f_auto/face', ['Accept: image/webp'], 'webp', 60, 87],
        // Cloudinary places the version before the chain.
        ['/image/upload/v1699999999/w_70/demo', [], 'jpg', 70, 47],
    ];
    $bodies = [];
    foreach ($matrix as [$path, $headers, $format, $width, $height]) {
        $response = $get($base, $path, $headers);
        $label = $path . ($headers === [] ? '' : ' [' . $headers[0] . ']');
        if ($response['status'] !== 200) {
            $check(false, $label . ' -> HTTP ' . $response['status'] . ' ' . substr($response['body'], 0, 160));
            continue;
        }
        $check($response['format'] === $format, $label . ' -> sniffed ' . $response['format'] . ', expected ' . $format);
        $check(str_contains(strtolower($response['content-type'] ?? ''), str_replace('jpg', 'jpeg', $format)),
            $label . ' -> Content-Type ' . ($response['content-type'] ?? 'none'));
        $size = getimagesizefromstring($response['body']);
        $check($size !== false && $size[0] === $width, $label . ' -> width ' . ($size[0] ?? 'n/a') . ', expected ' . $width);
        $check($size !== false && $size[1] === $height, $label . ' -> height ' . ($size[1] ?? 'n/a') . ', expected ' . $height);
        // Negotiated (f_auto) and delivery-extension rows are the same transformation
        // as an explicit row, so they must not count for the uniqueness check.
        if (!str_contains($path, '/f_auto/') && !str_ends_with($path, '/demo.webp')) { $bodies[$label] = $response['body']; }
        if (str_ends_with($path, '/f_auto/blend') || str_contains($label, 'Accept: image/webp,image/*')) {
            $check(str_contains(strtolower($response['vary'] ?? ''), 'accept'), $label . ' -> f_auto must Vary on Accept');
        }
    }
    // Same pixels must not collapse to one blob for different keys.
    $check(count(array_unique(array_values($bodies))) === count($bodies), 'distinct transformations produced identical bytes');

    // ---- 3. Cache identity, MISS -> HIT, conditional GET. ------------------------
    $key = '/image/upload/c_fill,w_123,h_77,f_webp/face';
    $miss = $get($base, $key);
    $check($miss['status'] === 200 && ($miss['x-evathumber-cache'] ?? '') === 'MISS', 'first request must be MISS');
    $hit = $get($base, $key);
    $check($hit['status'] === 200 && ($hit['x-evathumber-cache'] ?? '') === 'HIT', 'second request must be HIT');
    $check($hit['body'] === $miss['body'] && $hit['etag'] === $miss['etag'], 'HIT changed bytes or ETag');
    $check($get($base, $key, ['If-None-Match: ' . $miss['etag']])['status'] === 304, 'If-None-Match must return 304');
    $head = $get($base, $key, [], 'HEAD');
    $check($head['status'] === 200 && $head['body'] === '', 'HEAD must be empty');
    $check((int) ($head['content-length'] ?? -1) === strlen($miss['body']), 'HEAD Content-Length mismatch');
    $check($get($base, $key, ['If-None-Match: "other"'])['status'] === 200, 'stale ETag must return 200');
    $before = $cached();
    $check($before > 0, 'cache directory must hold products');
    // Analytics parameters must not fork cache identity.
    $analytics = $get($base, $key . '?_a=ZZZZ&_i=1234');
    $check($analytics['status'] === 200 && ($analytics['x-evathumber-cache'] ?? '') === 'HIT', 'analytics parameters must keep identity');
    $check($cached() === $before, 'analytics request must not create a cache entry');
    // A different version is a different cache entry for the same pixels.
    $versioned = $get($base, '/image/upload/v1699999999' . substr($key, strlen('/image/upload')));
    $check($versioned['status'] === 200 && ($versioned['x-evathumber-cache'] ?? '') === 'MISS', 'new version must be a MISS');
    $check($versioned['body'] === $miss['body'], 'version must not change pixels');
    $check($get($base, '/image/upload/v1699999999' . substr($key, strlen('/image/upload')))['x-evathumber-cache'] === 'HIT', 'versioned entry must then HIT');
    // Unsupported input is a bounded 4xx, never a hang or a 5xx.
    $check($get($base, '/image/upload/g_auto,w_100/demo')['status'] === 400, 'g_auto must be rejected');
    $check($get($base, '/image/upload/w_100/missing-source')['status'] === 404, 'unknown source must be 404');
    $check($get($base, '/image/upload/w_100/demo.tif')['status'] === 415, 'unsupported delivery format must be 415');

    // ---- 4. Restart keeps the derived cache and the service recovers. -----------
    // Ephemeral host ports are re-assigned by every restart on this daemon, so
    // the mapping must be re-read before probing again.
    $docker(['restart', '--timeout', '30', 'eva-accept-a']);
    $base = 'http://' . $docker(['port', 'eva-accept-a', '8080']);
    $restarted = $await($base);
    $check($restarted['ms'] > 0, 'restart must be measured');
    $afterRestart = $get($base, $key);
    $check($afterRestart['status'] === 200 && ($afterRestart['x-evathumber-cache'] ?? '') === 'HIT', 'HIT must survive docker restart');
    $check($afterRestart['body'] === $miss['body'] && $afterRestart['etag'] === $miss['etag'], 'restart changed cached bytes');
    $check($get($base, '/image/upload/w_121/f_webp/face')['status'] === 200, 'new misses must work after restart');
    $docker(['stop', '--timeout', '30', 'eva-accept-a']);
    $state = json_decode($docker(['inspect', '--format', '{{json .State}}', 'eva-accept-a']), true, flags: JSON_THROW_ON_ERROR);
    $check(!$state['Running'] && !$state['OOMKilled'] && $state['ExitCode'] === 0, 'graceful stop must exit 0 without OOMKilled');
    $docker(['start', 'eva-accept-a']);
    $base = 'http://' . $docker(['port', 'eva-accept-a', '8080']);
    $await($base);
    $check($get($base, $key)['x-evathumber-cache'] === 'HIT', 'HIT must survive stop/start');
    $remove('eva-accept-a');

    // ---- 5. Optional persistent cache volume, reused by a second container. -----
    $docker(['volume', 'create', $volume]);
    $run('eva-accept-b', ['-v', $source . ':/data/images:ro', '-v', $volume . ':/data/cache']);
    $base = 'http://' . $docker(['port', 'eva-accept-b', '8080']);
    $await($base);
    $volumeKey = '/image/upload/c_fill,w_321,h_123/f_webp/blend.png';
    $check($get($base, $volumeKey)['x-evathumber-cache'] === 'MISS', 'fresh volume must be a MISS');
    $check($get($base, $volumeKey)['x-evathumber-cache'] === 'HIT', 'second request must HIT');
    $remove('eva-accept-b');
    $run('eva-accept-c', ['-v', $source . ':/data/images:ro', '-v', $volume . ':/data/cache']);
    $base = 'http://' . $docker(['port', 'eva-accept-c', '8080']);
    $await($base);
    $shared = $get($base, $volumeKey);
    $check($shared['status'] === 200 && ($shared['x-evathumber-cache'] ?? '') === 'HIT', 'a new container must HIT a persisted cache');
    $check($shared['body'] === $miss['body'] || $shared['format'] === 'webp', 'persisted cache must stay decodable');
    // Removing derived data must never affect originals or correctness.
    $remove('eva-accept-c');
    $docker(['volume', 'rm', '-f', $volume]);
    $run('eva-accept-d', ['-v', $source . ':/data/images:ro']);
    $base = 'http://' . $docker(['port', 'eva-accept-d', '8080']);
    $await($base);
    $check($get($base, $volumeKey)['x-evathumber-cache'] === 'MISS', 'without a volume the cache starts empty');
    $rebuilt = $get($base, $volumeKey);
    $check($rebuilt['status'] === 200 && $rebuilt['body'] === $shared['body'], 'a rebuilt product must be byte-identical');
    $check(is_file($source . '/demo.jpg') && hash_file('sha256', $source . '/demo.jpg') === hash_file('sha256', $root . '/upload/demo.jpg'),
        'originals must be untouched');
} catch (Throwable $error) {
    $failures[] = get_class($error) . ': ' . $error->getMessage();
} finally {
    foreach (['eva-accept-a', 'eva-accept-b', 'eva-accept-c', 'eva-accept-d'] as $name) {
        $docker(['rm', '-f', $name], allowFailure: true);
    }
    $docker(['volume', 'rm', '-f', $volume], allowFailure: true);
    foreach (new \DirectoryIterator($source) as $file) {
        if (!$file->isDot()) {
            unlink($file->getPathname());
        }
    }
    rmdir($source);
}

$summary = [
    'image' => $image,
    'platform' => $platform,
    'command' => 'docker run -p 8080:8080 -v <photos>:/data/images:ro ' . $image,
    'ready_ms' => $ready['ms'] ?? null,
    'readiness_attempts' => $ready['attempts'] ?? null,
    'restart_ready_ms' => $restarted['ms'] ?? null,
    'checks' => $checks,
    'failures' => $failures,
    'verdict' => $failures === [] ? 'passed' : 'failed',
    'finished_utc' => gmdate(DATE_ATOM),
];
if (($evidencePath = getenv('EVATHUMBER_ACCEPTANCE_EVIDENCE')) !== false) {
    if (!is_dir($evidencePath)) {
        mkdir($evidencePath, 0770, true);
    }
    file_put_contents($evidencePath . '/docker-acceptance.json', json_encode($summary + ['docker_calls' => $evidence], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failures === [] ? 0 : 1);
