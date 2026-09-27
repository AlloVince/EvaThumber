<?php

declare(strict_types=1);

// RC1 product acceptance: only the two flags the README tells a user to use.
//
//   docker run -p 8080:8080 -v <dir>:/data/images:ro IMAGE
//
// Phase A runs with no cache volume at all. Phase B adds the optional cache volume and
// proves a HIT survives a restart and that a wiped cache is harmless. Usage:
//   php tests/rc1-acceptance.php IMAGE [PLATFORM]
require dirname(__DIR__) . '/vendor/autoload.php';

use Jcupitt\Vips\Image;
use Symfony\Component\Process\Process;

$image = $argv[1] ?? throw new InvalidArgumentException('Usage: php tests/rc1-acceptance.php IMAGE [PLATFORM]');
$platform = $argv[2] ?? 'linux/arm64';
$work = sys_get_temp_dir() . '/eva-rc1-' . bin2hex(random_bytes(5));
$volume = 'eva-rc1-cache-' . bin2hex(random_bytes(5));
$containers = [];

$try = static function (array $command, float $timeout = 180): array {
    $process = new Process($command, dirname(__DIR__), timeout: $timeout);
    $process->run();
    return ['code' => $process->getExitCode() ?? 1, 'out' => trim($process->getOutput()), 'err' => trim($process->getErrorOutput())];
};
$must = static function (array $command, float $timeout = 180) use ($try): string {
    $result = $try($command, $timeout);
    if ($result['code'] !== 0) {
        throw new RuntimeException('Command failed: ' . implode(' ', $command) . "\n" . $result['err'] . $result['out']);
    }
    return $result['out'];
};
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
// A readiness failure must say why. Capture the container's log head as well as its
// tail: the pool supervisor reports its startup on the first lines, which a bare
// `--tail` drops once a polling loop has filled the buffer with access-log lines.
$logExcerpt = static function (string $name) use ($try): string {
    $logs = $try(['docker', 'logs', $name], 30);
    $lines = explode("\n", $logs['out'] . $logs['err']);
    return "--- log head ---\n" . implode("\n", array_slice($lines, 0, 40))
        . "\n--- log tail ---\n" . implode("\n", array_slice($lines, -25));
};
// The documented invocation, unchanged: only the image mount and the published port.
$probe = static function (string $url): array {
    $process = new Process(['curl', '--silent', '--show-error', '--max-time', '2', '--noproxy', '*',
        '--write-out', "\n%{http_code}", $url], timeout: 5);
    $process->run();
    $output = $process->getOutput();
    $split = strrpos($output, "\n");
    return $split === false
        ? ['status' => 0, 'body' => trim($output)]
        : ['status' => (int) trim(substr($output, $split + 1)), 'body' => trim(substr($output, 0, $split))];
};
$start = static function (string $name, array $extra = []) use ($must, $try, $check, $probe, $logExcerpt, $work, $image, $platform, &$containers): string {
    array_push($extra, '-d', '--name', $name, '--platform', $platform,
        '-p', '127.0.0.1::8080', '-v', $work . '/images:/data/images:ro', $image);
    $must(array_merge(['docker', 'run'], $extra));
    $containers[] = $name;
    $base = 'http://' . $must(['docker', 'port', $name, '8080']);
    $started = hrtime(true);
    $live = ['status' => 0, 'body' => ''];
    $ready = ['status' => 0, 'body' => 'no probe reached the container'];
    do {
        $live = $probe($base . '/healthz');
        $ready = $probe($base . '/readyz');
        if ($live['status'] === 200 && $ready['status'] === 200) {
            return $base;
        }
        usleep(100_000);
    } while (hrtime(true) - $started < 30_000_000_000);
    $check(false, 'Container never became ready: ' . $name
        . "\nlast healthz: " . json_encode($live, JSON_UNESCAPED_SLASHES)
        . "\nlast readyz: " . json_encode($ready, JSON_UNESCAPED_SLASHES)
        . "\nstate: " . $try(['docker', 'inspect', '--format', '{{json .State}}', $name], 20)['out']
        . "\n" . $logExcerpt($name));
    throw new LogicException('unreachable');
};
$stop = static function (string $name) use ($try, $must, $check): array {
    $began = hrtime(true);
    $try(['docker', 'stop', '--timeout', '30', $name], 60);
    $state = json_decode($must(['docker', 'inspect', '--format', '{{json .State}}', $name]), true, flags: JSON_THROW_ON_ERROR);
    $check(!$state['Running'] && !$state['OOMKilled'] && $state['ExitCode'] === 0,
        'Container must stop cleanly: ' . json_encode($state));
    return ['ms' => round((hrtime(true) - $began) / 1e6, 3), 'exit_code' => $state['ExitCode'], 'oom_killed' => $state['OOMKilled']];
};

$facts = [];
try {
    // Readable by any uid: the container runs as www-data (33) while this directory is
    // owned by whoever runs the suite, and a 0700 bind mount is unreadable to it on real
    // Linux. OrbStack remaps mount ownership to the container user, which hides this.
    if (!is_dir($work . '/images') && !mkdir($work . '/images', 0755, true)) {
        throw new RuntimeException('Cannot create the fixture directory.');
    }
    chmod($work . '/images', 0755);
    foreach (['demo.jpg', 'face.jpg', 'blend.png'] as $file) {
        $origin = dirname(__DIR__) . '/upload/' . $file;
        $check(is_file($origin), 'Missing fixture ' . $origin);
        copy($origin, $work . '/images/' . $file);
        $facts['fixtures'][$file] = ['sha256' => hash_file('sha256', $origin), 'bytes' => filesize($origin),
            'width' => getimagesize($origin)[0], 'height' => getimagesize($origin)[1]];
    }
    file_put_contents($work . '/images/broken.jpg', "not an image at all\n");
    $facts['image'] = ['id' => $must(['docker', 'image', 'inspect', '--format', '{{.Id}}', $image]),
        'architecture' => $must(['docker', 'image', 'inspect', '--format', '{{.Architecture}}', $image])];

    $phaseA = 'eva-rc1-a-' . bin2hex(random_bytes(4));
    $base = $start($phaseA);
    $request = static function (string $path, array $headers = [], string $method = 'GET', float $timeout = 60) use (&$base): array {
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => $timeout, 'ignore_errors' => true,
        ]]);
        $stream = @fopen($base . $path, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('HTTP request failed: ' . $path . ': ' . (error_get_last()['message'] ?? 'unknown'));
        }
        try {
            $lines = stream_get_meta_data($stream)['wrapper_data'];
            $body = (string) stream_get_contents($stream);
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
    // Every successful image response must decode, not merely return 200.
    $expect = static function (string $label, array $response, int $status, string $mime, ?int $width = null, ?int $height = null) use ($check): array {
        $check($response['status'] === $status, $label . ': expected HTTP ' . $status . ', got ' . $response['status'] . ' ' . substr($response['body'], 0, 120));
        if ($mime !== '') {
            $check(($response['content-type'] ?? '') === $mime, $label . ': expected ' . $mime . ', got ' . ($response['content-type'] ?? 'none'));
        }
        try {
            $size = Image::newFromBuffer($response['body']);
        } catch (Throwable $error) {
            throw new RuntimeException($label . ': response is not a decodable image: ' . $error->getMessage());
        }
        if ($width !== null) {
            $check($size->width === $width, $label . ': expected width ' . $width . ', got ' . $size->width);
        }
        if ($height !== null) {
            $check($size->height === $height, $label . ': expected height ' . $height . ', got ' . $size->height);
        }
        return [$size->width, $size->height];
    };

    $check($request('/healthz')['status'] === 200, 'Liveness must answer 200.');
    $ready = $request('/readyz');
    $check($ready['status'] === 200 && json_decode($ready['body'], true) === ['status' => 'ready'],
        'Readiness must answer ready, got ' . $ready['status'] . ' ' . $ready['body']);
    $check($must(['docker', 'exec', $phaseA, 'id', '-u']) !== '0', 'Container must not run as root.');
    $check($try(['docker', 'exec', $phaseA, 'sh', '-c', 'echo x > /data/images/nope'])['code'] !== 0,
        'The source mount must stay read-only inside the container.');

    $expect('jpeg fill webp', $request('/image/upload/c_fill,w_300,h_300/f_webp/demo.jpg'), 200, 'image/webp', 300, 300);
    $expect('png fill webp', $request('/image/upload/c_fill,w_300,h_300/f_webp/blend.png'), 200, 'image/webp', 300, 300);
    $expect('jpeg avif', $request('/image/upload/w_400/f_avif/face.jpg'), 200, 'image/avif', 400, null);
    $expect('jpeg resize', $request('/image/upload/w_200/demo.jpg'), 200, 'image/jpeg', 200, null);
    $expect('jpeg png', $request('/image/upload/w_150/f_png/demo.jpg'), 200, 'image/png', 150, null);
    $expect('crop north west', $request('/image/upload/c_crop,w_200,h_150,g_north_west/demo.jpg'), 200, 'image/jpeg', 200, 150);
    $expect('chained fill q_75 webp', $request('/image/upload/c_fill,w_320,h_240,g_south/q_75/f_webp/blend.png'), 200, 'image/webp', 320, 240);
    $expect('dpr 2', $request('/image/upload/w_300,dpr_2/demo.jpg'), 200, 'image/jpeg', 600, null);
    $autoWebp = $request('/image/upload/w_250,f_auto/demo.jpg', ['Accept: image/webp,image/*;q=0.8']);
    $expect('f_auto webp', $autoWebp, 200, 'image/webp', 250, null);
    $check(str_contains(strtolower($autoWebp['vary'] ?? ''), 'accept'), 'f_auto must vary on Accept.');
    $autoAvif = $request('/image/upload/w_251,f_auto/q_auto:best/demo.jpg', ['Accept: image/avif,image/webp;q=0.5']);
    $expect('f_auto avif', $autoAvif, 200, 'image/avif', 251, null);
    $expect('q_auto webp eco', $request('/image/upload/w_252,q_auto:eco/f_webp/demo.jpg'), 200, 'image/webp', 252, null);
    $expect('q_auto jpeg', $request('/image/upload/w_253,q_auto/face.jpg'), 200, 'image/jpeg', 253, null);
    $check($request('/image/upload/w_100/e_nonexistent/demo.jpg')['status'] === 400, 'Unsupported effects must be rejected.');
    $check($request('/image/upload/c_fill,w_300,h_300/f_webp/missing.jpg')['status'] === 404, 'A missing source must be 404.');
    $broken = $request('/image/upload/w_120/broken.jpg');
    $check($broken['status'] >= 400 && $broken['status'] < 600, 'A malformed source must not succeed, got ' . $broken['status']);

    $path = '/image/upload/c_fill,w_300,h_300/f_webp/demo.jpg';
    $cold = $request($path);
    $check($cold['x-evathumber-cache'] === 'HIT' || $cold['x-evathumber-cache'] === 'MISS', 'Cache state header missing.');
    $first = $cold['x-evathumber-cache'] === 'MISS' ? $cold : $request('/image/upload/c_fill,w_301,h_301/f_webp/demo.jpg');
    $key = $first === $cold ? $path : '/image/upload/c_fill,w_301,h_301/f_webp/demo.jpg';
    $hit = $request($key);
    $check($hit['x-evathumber-cache'] === 'HIT', 'A second request must be a HIT.');
    $check($hit['body'] === $first['body'] && $hit['etag'] === $first['etag'], 'A HIT must return identical bytes and ETag.');
    $check($request($key, ['If-None-Match: ' . $first['etag']])['status'] === 304, 'A conditional request must return 304.');
    $head = $request($key, [], 'HEAD');
    $check($head['status'] === 200 && $head['body'] === '' && (int) $head['content-length'] === strlen($first['body']),
        'HEAD must mirror the GET length.');
    $check($request($key . '?_a=rc1')['x-evathumber-cache'] === 'HIT', 'Analytics must not change cache identity.');

    // A rejected request must not damage the service or the products around it.
    $check($request('/healthz')['status'] === 200, 'Liveness must survive rejected requests.');
    $check($request('/readyz')['status'] === 200, 'Readiness must survive rejected requests.');
    $check($request($key)['body'] === $first['body'], 'Cached bytes must survive rejected requests.');
    $facts['phase_a'] = ['no_cache_volume' => true, 'malformed_source_status' => $broken['status'], 'stop' => $stop($phaseA)];

    $must(['docker', 'volume', 'create', $volume]);
    $phaseB = 'eva-rc1-b-' . bin2hex(random_bytes(4));
    $base = $start($phaseB, ['-v', $volume . ':/data/cache']);
    $second = '/image/upload/c_fill,w_321,h_321/f_webp/face.jpg';
    $cold = $request($second);
    $check($cold['status'] === 200 && $cold['x-evathumber-cache'] === 'MISS', 'A fresh cache volume must MISS first.');
    $check($request($second)['x-evathumber-cache'] === 'HIT', 'The second request must HIT.');
    $must(['docker', 'restart', '--timeout', '30', $phaseB]);
    $base = 'http://' . $must(['docker', 'port', $phaseB, '8080']);
    $must(['curl', '-fsS', '--retry', '60', '--retry-all-errors', '--retry-connrefused', '--retry-delay', '0', '--max-time', '30', '--noproxy', '*', $base . '/healthz'], 60);
    $restarted = $request($second);
    $check($restarted['status'] === 200 && $restarted['x-evathumber-cache'] === 'HIT',
        'A persisted cache must still HIT after restart, got ' . ($restarted['x-evathumber-cache'] ?? 'none'));
    $check($restarted['body'] === $cold['body'] && $restarted['etag'] === $cold['etag'], 'A restart must not change cached bytes.');
    // Lock files are runtime infrastructure (dotfiles, recreated on demand), not products.
    $wiped = $try(['docker', 'exec', $phaseB, 'sh', '-c', 'rm -rf /data/cache/* && find /data/cache -type f ! -name "*.lock" | wc -l']);
    $check(trim($wiped['out']) === '0', 'The cache must be removable from inside the container: ' . var_export($wiped['out'], true));
    $rebuilt = $request($second);
    $check($rebuilt['status'] === 200 && $rebuilt['x-evathumber-cache'] === 'MISS', 'A wiped cache must regenerate as MISS.');
    $check($rebuilt['body'] === $cold['body'], 'A regenerated product must be byte-identical.');
    $expect('sources survive a cache wipe', $request('/image/upload/w_120/f_webp/blend.png'), 200, 'image/webp', 120, null);
    $facts['phase_b'] = ['volume' => $volume, 'hit_after_restart' => true, 'cache_wipe_safe' => true, 'stop' => $stop($phaseB)];

    echo json_encode(['image' => $image, 'platform' => $platform, 'result' => 'passed'] + $facts,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    foreach ($containers as $name) {
        fwrite(STDERR, $logExcerpt($name) . "\n");
    }
    throw $error;
} finally {
    foreach ($containers as $name) {
        $try(['docker', 'rm', '-f', $name], 60);
    }
    $try(['docker', 'volume', 'rm', '-f', $volume], 60);
    foreach (glob($work . '/images/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($work . '/images');
    @rmdir($work);
}
