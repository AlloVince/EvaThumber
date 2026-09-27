<?php

declare(strict_types=1);

// Product acceptance: exactly the promises the README makes, against a real Docker
// daemon, using only the two flags a user is told to use.
//
//   docker run -p 8080:8080 -v <dir>:/data/images:ro IMAGE
//
// Phase A runs with no cache volume at all; phase B adds the optional cache volume and
// proves HIT survives a restart and that deleting the cache is harmless.
// Usage: php tests/product-acceptance.php IMAGE [PLATFORM]
require dirname(__DIR__) . '/vendor/autoload.php';

use Jcupitt\Vips\Image;
use Symfony\Component\Process\Process;

$image = $argv[1] ?? throw new InvalidArgumentException('Usage: php tests/product-acceptance.php IMAGE [PLATFORM]');
$platform = $argv[2] ?? 'linux/arm64';
$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/eva-product-' . bin2hex(random_bytes(6));
$volume = 'eva-product-cache-' . bin2hex(random_bytes(6));
$names = [];

$run = static function (array $command, float $timeout = 120) use ($work): string {
    $process = new Process($command, $work, timeout: $timeout);
    $process->mustRun();
    return trim($process->getOutput());
};
$try = static function (array $command, float $timeout = 120): array {
    $process = new Process($command, dirname(__DIR__), timeout: $timeout);
    $process->run();
    return ['code' => $process->getExitCode() ?? 1, 'out' => $process->getOutput() . $process->getErrorOutput()];
};
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$awaitLive = static function (string $base) use ($try): float {
    $started = hrtime(true);
    do {
        $probe = new Process(['curl', '-fsS', '--max-time', '1', '--noproxy', '*', $base . '/healthz'], timeout: 5);
        $probe->run();
        if ($probe->isSuccessful()) {
            return round((hrtime(true) - $started) / 1e6, 3);
        }
        usleep(100_000);
    } while (hrtime(true) - $started < 20_000_000_000);
    throw new RuntimeException('Container never became live at ' . $base);
};
$start = static function (string $name, array $extra = [], ?string $source = null) use ($run, $try, $awaitLive, $work, $image, $platform, &$names): string {
    array_push($extra, '-d', '--name', $name, '--platform', $platform,
        '-p', '127.0.0.1::8080', '-v', ($source ?? $work . '/source') . ':/data/images:ro', $image);
    $run(array_merge(['docker', 'run'], $extra));
    $names[] = $name;
    $base = 'http://' . $run(['docker', 'port', $name, '8080']);
    $awaitLive($base);
    return $base;
};
$stop = static function (string $name) use ($run, $try, $check): array {
    $stopped = hrtime(true);
    $try(['docker', 'stop', '--timeout', '30', $name], 60);
    $state = json_decode($run(['docker', 'inspect', '--format', '{{json .State}}', $name]), true, flags: JSON_THROW_ON_ERROR);
    $check(!$state['Running'] && !$state['OOMKilled'] && $state['ExitCode'] === 0,
        'Container did not stop cleanly: ' . json_encode($state));
    return ['ms' => round((hrtime(true) - $stopped) / 1e6, 3), 'exit_code' => $state['ExitCode'], 'oom_killed' => $state['OOMKilled']];
};

$facts = [];
try {
    mkdir($work . '/source', 0700, true);
    foreach (['demo.jpg' => 'image/jpeg', 'face.jpg' => 'image/jpeg', 'blend.png' => 'image/png'] as $file => $mime) {
        $origin = $root . '/upload/' . $file;
        $check(is_file($origin), 'Missing fixture ' . $origin);
        copy($origin, $work . '/source/' . $file);
        $facts['fixtures'][$file] = ['sha256' => hash_file('sha256', $origin), 'bytes' => filesize($origin),
            'size' => array_slice(getimagesize($origin), 0, 3)];
    }
    $facts['image'] = ['id' => $run(['docker', 'image', 'inspect', '--format', '{{.Id}}', $image]),
        'architecture' => $run(['docker', 'image', 'inspect', '--format', '{{.Architecture}}', $image])];
    Image::newFromFile($work . '/source/demo.jpg')->resize(6)->jpegsave($work . '/source/large.jpg', ['Q' => 90]);
    $facts['fixtures']['large.jpg'] = ['provenance' => 'upload/demo.jpg enlarged 6x, JPEG Q90',
        'sha256' => hash_file('sha256', $work . '/source/large.jpg'), 'bytes' => filesize($work . '/source/large.jpg'),
        'size' => array_slice(getimagesize($work . '/source/large.jpg'), 0, 3)];

    // Phase A: the documented one-liner, with no cache volume and no hardening flags.
    $phaseA = 'eva-product-a-' . bin2hex(random_bytes(4));
    $base = $start($phaseA);
    $request = static function (string $path, array $headers = [], string $method = 'GET', float $timeout = 30) use (&$base): array {
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => $timeout, 'ignore_errors' => true,
        ]]);
        $stream = @fopen($base . $path, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('HTTP request failed: ' . $path . ': ' . (error_get_last()['message'] ?? 'unknown'));
        }
        try {
            $meta = stream_get_meta_data($stream);
            $body = (string) stream_get_contents($stream);
            $lines = $meta['wrapper_data'];
            $result = ['status' => (int) explode(' ', $lines[0])[1], 'body' => $body];
            foreach (array_slice($lines, 1) as $line) {
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
    $decode = static function (string $body, string $label): array {
        try {
            $decoded = Image::newFromBuffer($body);
            return [$decoded->width, $decoded->height];
        } catch (Throwable $error) {
            throw new RuntimeException('Response is not a decodable image (' . $label . '): ' . $error->getMessage());
        }
    };
    $expect = static function (string $label, array $response, int $status, string $mime, ?int $width, ?int $height) use ($check, $decode): array {
        $check($response['status'] === $status, $label . ': expected HTTP ' . $status . ', got ' . $response['status'] . ' ' . substr($response['body'], 0, 200));
        if ($mime !== '') {
            $check(($response['content-type'] ?? '') === $mime, $label . ': expected ' . $mime . ', got ' . ($response['content-type'] ?? 'none'));
        }
        $size = $decode($response['body'], $label);
        $check($width === null || $size[0] === $width, $label . ': expected width ' . $width . ', got ' . $size[0]);
        $check($height === null || $size[1] === $height, $label . ': expected height ' . $height . ', got ' . $size[1]);
        return $size;
    };

    $health = $request('/healthz');
    $check($health['status'] === 200, 'Liveness must answer 200.');
    $ready = $request('/readyz');
    $check($ready['status'] === 200 && json_decode($ready['body'], true) === ['status' => 'ready'],
        'Readiness must answer ready: ' . $ready['status'] . ' ' . $ready['body']);
    $check($run(['docker', 'exec', $phaseA, 'id', '-u']) !== '0', 'Container must not run as root.');
    $write = $try(['docker', 'exec', $phaseA, 'sh', '-c', 'echo x > /data/images/should-fail']);
    $check($write['code'] !== 0, 'Source mount must stay read-only inside the container.');
    $facts['no_cache_volume'] = true;

    // Declared transformation surface, on the documented default port.
    $expect('jpeg->webp fill', $request('/image/upload/c_fill,w_300,h_300/f_webp/demo.jpg'), 200, 'image/webp', 300, 300);
    $expect('png->webp fill', $request('/image/upload/c_fill,w_300,h_300/f_webp/blend.png'), 200, 'image/webp', 300, 300);
    $expect('jpeg->avif', $request('/image/upload/w_400/f_avif/face.jpg'), 200, 'image/avif', 400, null);
    $expect('jpeg->jpeg resize', $request('/image/upload/w_200/demo.jpg'), 200, 'image/jpeg', 200, null);
    $expect('jpeg->png', $request('/image/upload/w_150/f_png/demo.jpg'), 200, 'image/png', 150, null);
    $expect('crop nw', $request('/image/upload/c_crop,w_100,h_100,g_north_west/demo.jpg'), 200, 'image/jpeg', 100, 100);
    $expect('chained crop then webp', $request('/image/upload/c_fill,w_320,h_240,g_south/q_75/f_webp/blend.png'), 200, 'image/webp', 320, 240);
    $auto = $request('/image/upload/w_250,f_auto/demo.jpg', ['Accept: image/webp,image/*;q=0.8']);
    $expect('f_auto negotiates webp', $auto, 200, 'image/webp', 250, null);
    $check(str_contains(strtolower($auto['vary'] ?? ''), 'accept'), 'f_auto must vary on Accept.');
    $expect('f_auto negotiates jpeg', $request('/image/upload/w_251,f_auto/demo.jpg', ['Accept: image/jpeg']), 200, 'image/jpeg', 251, null);
    $expect('f_auto breaks the webp tie by quality', $request('/image/upload/w_252,f_auto/q_auto:best/demo.jpg', ['Accept: image/avif,image/webp;q=0.5']), 200, 'image/avif', 252, null);
    $expect('f_auto prefers webp on equal quality', $request('/image/upload/w_255,f_auto/demo.jpg', ['Accept: image/avif,image/webp']), 200, 'image/webp', 255, null);
    $expect('q_auto webp', $request('/image/upload/w_253,q_auto:eco/f_webp/demo.jpg'), 200, 'image/webp', 253, null);
    $expect('q_auto jpeg', $request('/image/upload/w_254,q_auto/face.jpg'), 200, 'image/jpeg', 254, null);
    foreach (['best', 'good', 'eco', 'low'] as $tier) {
        $expect('q_auto:' . $tier, $request('/image/upload/c_scale,w_900/q_auto:' . $tier . '/f_jpg/large.jpg'),
            200, 'image/jpeg', 900, null);
    }
    $expect('large source to webp', $request('/image/upload/c_scale,w_1600/f_webp/large.jpg'), 200, 'image/webp', 1600, null);
    $expect('large source to avif', $request('/image/upload/c_scale,w_1200/f_avif/large.jpg'), 200, 'image/avif', 1200, null);
    $expect('dpr', $request('/image/upload/w_300,dpr_2/demo.jpg'), 200, 'image/jpeg', 600, 400);
    $expect('grayscale effect', $request('/image/upload/w_100/e_grayscale/demo.jpg'), 200, 'image/jpeg', 100, null);
    $expect('flip chain', $request('/image/upload/c_fill,w_120,h_90/a_hflip/f_webp/demo.jpg'), 200, 'image/webp', 120, 90);
    $expect('rotate chain', $request('/image/upload/c_fill,w_120,h_90/a_180/f_webp/demo.jpg'), 200, 'image/webp', 120, 90);
    $check($request('/image/upload/w_100,e_grayscale/demo.jpg')['status'] === 400, 'Resize and effect must be chained, not merged.');
    $check($request('/image/upload/w_100/e_nonexistent/demo.jpg')['status'] === 400, 'Unsupported effects must be rejected.');
    $check($request('/image/upload/c_fill,w_300,h_300/f_webp/missing.jpg')['status'] === 404, 'Missing source must be 404.');

    // MISS -> HIT -> 304 on the very first URL a user would request.
    $first = $request('/image/upload/c_fill,w_300,h_300/f_webp/demo.jpg');
    $hit = $request('/image/upload/c_fill,w_300,h_300/f_webp/demo.jpg');
    $check($hit['x-evathumber-cache'] === 'HIT', 'Second request must be a HIT, got ' . ($hit['x-evathumber-cache'] ?? 'none'));
    $check($hit['body'] === $first['body'] && $hit['etag'] === $first['etag'], 'HIT must return identical bytes and ETag.');
    $check($request('/image/upload/c_fill,w_300,h_300/f_webp/demo.jpg', ['If-None-Match: ' . $first['etag']])['status'] === 304,
        'Conditional request must return 304.');
    $head = $request('/image/upload/c_fill,w_300,h_300/f_webp/demo.jpg', [], 'HEAD');
    $check($head['status'] === 200 && $head['body'] === '' && (int) $head['content-length'] === strlen($first['body']), 'HEAD must mirror GET length.');
    $facts['phase_a'] = ['no_cache_volume' => true, 'stop' => $stop($phaseA)];

    // Phase B: the optional persistent cache volume.
    $run(['docker', 'volume', 'create', $volume]);
    $phaseB = 'eva-product-b-' . bin2hex(random_bytes(4));
    $base = $start($phaseB, ['-v', $volume . ':/data/cache']);
    $path = '/image/upload/c_fill,w_321,h_321/f_webp/face.jpg';
    $cold = $request($path);
    $check($cold['status'] === 200 && $cold['x-evathumber-cache'] === 'MISS', 'First request on a fresh volume must be a MISS.');
    $check($request($path)['x-evathumber-cache'] === 'HIT', 'Second request must be a HIT.');
    $run(['docker', 'restart', '--timeout', '30', $phaseB]);
    $base = 'http://' . $run(['docker', 'port', $phaseB, '8080']);
    $run(['curl', '-fsS', '--retry', '60', '--retry-all-errors', '--retry-connrefused', '--retry-delay', '0',
        '--max-time', '30', '--noproxy', '*', $base . '/readyz']);
    $afterRestart = $request($path);
    $check($afterRestart['status'] === 200 && $afterRestart['x-evathumber-cache'] === 'HIT',
        'Persisted cache must still HIT after restart, got ' . ($afterRestart['x-evathumber-cache'] ?? 'none'));
    $check($afterRestart['body'] === $cold['body'] && $afterRestart['etag'] === $cold['etag'], 'Restart must not change cached bytes.');
    // The cache is derived data: destroying it must not affect sources or correctness.
    // Lock files live beside the products and are recreated, so only products are counted.
    $wipe = $try(['docker', 'exec', $phaseB, 'sh', '-c', 'rm -rf /data/cache/* && find /data/cache -type f ! -name "*.lock" | wc -l']);
    $check(trim($wipe['out']) === '0', 'Cache wipe failed: ' . var_export($wipe['out'], true));
    $rebuilt = $request($path);
    $check($rebuilt['status'] === 200 && $rebuilt['x-evathumber-cache'] === 'MISS', 'A wiped cache must regenerate as MISS.');
    $check($rebuilt['body'] === $cold['body'], 'Regenerated product must be byte-identical.');
    $expect('source untouched after cache wipe', $request('/image/upload/w_120/f_webp/blend.png'), 200, 'image/webp', 120, null);
    $facts['phase_b'] = ['volume' => $volume, 'hit_after_restart' => true, 'cache_wipe_safe' => true, 'stop' => $stop($phaseB)];

    // Phase C: readiness has to mean something, so a read-only cache mount must fail it
    // while liveness keeps answering. This is the mistake a first-time user makes.
    mkdir($work . '/frozen');
    $phaseC = 'eva-product-c-' . bin2hex(random_bytes(4));
    $base = $start($phaseC, ['-v', $work . '/frozen:/data/cache:ro']);
    $unready = $request('/readyz');
    $check($unready['status'] === 503, 'Readiness must fail when the cache is not writable, got ' . $unready['status']);
    $check($request('/healthz')['status'] === 200, 'Liveness must survive an unusable cache.');
    $facts['phase_c'] = ['readyz' => $unready['status'], 'readyz_body' => json_decode($unready['body'], true),
        'healthz' => 200, 'stop' => $stop($phaseC)];

    echo json_encode(['image' => $image, 'platform' => $platform, 'result' => 'passed'] + $facts,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    foreach ($names as $name) {
        $diagnostic = $try(['docker', 'logs', '--tail', '60', $name], 15);
        if ($diagnostic['out'] !== '') {
            fwrite(STDERR, $diagnostic['out'] . "\n");
        }
    }
    throw $error;
} finally {
    foreach ($names as $name) {
        $try(['docker', 'rm', '-f', $name], 30);
    }
    $try(['docker', 'volume', 'rm', '-f', $volume], 30);
    foreach (glob($work . '/source/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($work . '/source');
    @rmdir($work . '/frozen');
    @rmdir($work);
}
