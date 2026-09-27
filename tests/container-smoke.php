<?php

declare(strict_types=1);

// Host-side acceptance runner. Requires vendor dependencies and a running Docker daemon.
require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Process\Process;

$image = $argv[1] ?? throw new InvalidArgumentException('Usage: php tests/container-smoke.php IMAGE [PLATFORM]');
$platform = $argv[2] ?? 'linux/arm64';
$name = 'evathumber-smoke-' . bin2hex(random_bytes(6));
$run = static function (array $command, float $timeout = 60): string {
    $process = new Process($command);
    $process->setTimeout($timeout);
    $process->mustRun();
    return trim($process->getOutput());
};
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$created = false;
try {
    $run(['docker', 'create', '--name', $name, '--platform', $platform, '--read-only',
        '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
        '--memory', '512m', '--cpus', '2', '--pids-limit', '128',
        '--tmpfs', '/tmp', '--tmpfs', '/config/caddy:uid=33,gid=33',
        '--tmpfs', '/data/caddy:uid=33,gid=33', '--tmpfs', '/data/cache:uid=33,gid=33',
        '--tmpfs', '/data/images:uid=33,gid=33', '-e', 'EVATHUMBER_PHP_BINARY=/usr/local/bin/php',
        '-p', '127.0.0.1::8080', $image]);
    $created = true;
    $run(['docker', 'start', $name]);
    $address = $run(['docker', 'port', $name, '8080']);
    $base = 'http://' . $address;
    $request = static function (string $path, array $headers = [], string $method = 'GET', float $timeout = 20) use ($base): array {
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers),
            'timeout' => $timeout, 'ignore_errors' => true,
        ]]);
        error_clear_last();
        $stream = @fopen($base . $path, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('HTTP connection failed: ' . $path . ': ' . (error_get_last()['message'] ?? 'unknown error'));
        }
        try {
            $meta = stream_get_meta_data($stream);
            $body = stream_get_contents($stream);
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
    // Only readiness retries: a hung connection must not consume the entire budget.
    $started = hrtime(true);
    $deadline = $started + 20_000_000_000;
    $health = null;
    $attempts = [];
    do {
        $remaining = ($deadline - hrtime(true)) / 1e9;
        if ($remaining <= 0) {
            break;
        }
        try {
            $health = $request('/healthz', timeout: min(1.0, $remaining));
            if ($health['status'] === 200) {
                break;
            }
            $failure = 'HTTP ' . $health['status'];
        } catch (RuntimeException $error) {
            $failure = $error->getMessage();
        }
        $attempts[] = ['ms' => round((hrtime(true) - $started) / 1e6), 'error' => $failure];
        usleep(100_000);
    } while (hrtime(true) < $deadline);
    if ($attempts !== []) {
        fwrite(STDERR, json_encode(['address' => $address, 'readiness_retries' => $attempts], JSON_THROW_ON_ERROR) . PHP_EOL);
    }
    $readyMs = round((hrtime(true) - $started) / 1e6, 3);
    $check(($health['status'] ?? 0) === 200, 'Health endpoint did not become ready');
    $check(str_contains($health['cache-control'], 'no-store'), 'Health must not be cached');
    $ready = $request('/readyz');
    $check($ready['status'] === 200 && json_decode($ready['body'], true) === ['status' => 'ready'], 'Readiness must report ready: ' . $ready['body']);
    $check($run(['docker', 'exec', $name, 'id', '-u']) !== '0', 'Container must run nonroot');
    $run(['docker', 'exec', $name, 'php', '-d', 'ffi.enable=true', '-r',
        'require "/app/vendor/autoload.php"; \Jcupitt\Vips\Image::black(80, 60, ["bands" => 3])->pngsave("/data/images/smoke.png");']);
    $path = '/image/upload/w_20/smoke.jpg';
    $first = $request($path);
    $check($first['status'] === 200 && $first['x-evathumber-cache'] === 'MISS', 'First request must be MISS');
    $check($first['content-type'] === 'image/jpeg', 'Wrong delivery MIME');
    $size = getimagesizefromstring($first['body']);
    $check($size !== false && $size[0] === 20 && $size[1] === 15, 'Wrong output dimensions');
    $hit = $request($path);
    $check($hit['status'] === 200 && $hit['x-evathumber-cache'] === 'HIT', 'Second request must be HIT');
    $check($hit['body'] === $first['body'] && $hit['etag'] === $first['etag'], 'Cache bytes/ETag changed');
    $conditional = $request($path, ['If-None-Match: ' . $first['etag']]);
    $check($conditional['status'] === 304 && $conditional['body'] === '', 'Conditional GET must be empty 304');
    $head = $request($path, [], 'HEAD');
    $check($head['status'] === 200 && $head['body'] === '', 'HEAD must have no body');
    $check((int) $head['content-length'] === strlen($first['body']), 'HEAD length mismatch');
    $auto = $request('/image/upload/w_20/f_auto/smoke', ['Accept: image/webp']);
    $check($auto['status'] === 200 && $auto['content-type'] === 'image/webp', 'f_auto negotiation failed');
    $check(str_contains(strtolower($auto['vary']), 'accept'), 'f_auto must vary on Accept');
    $check($request('/image/upload/w_20/smoke.jpg?other=1')['status'] === 400, 'Unknown query must be rejected');
    $check($request($path . '?_a=smoke')['x-evathumber-cache'] === 'HIT', 'Analytics must preserve identity');
    $start = hrtime(true);
    $run(['docker', 'stop', '--timeout', '20', $name], 30);
    $state = json_decode($run(['docker', 'inspect', '--format', '{{json .State}}', $name]), true, flags: JSON_THROW_ON_ERROR);
    $check(!$state['Running'] && !$state['OOMKilled'] && $state['ExitCode'] === 0, 'Container did not stop cleanly');
    echo json_encode(['image' => $image, 'platform' => $platform, 'http' => 'passed',
        'ready_ms' => $readyMs, 'readiness_retries' => count($attempts),
        'stop_ms' => round((hrtime(true) - $start) / 1e6, 3), 'exit_code' => $state['ExitCode']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    if ($created) {
        foreach ([['docker', 'logs', $name],
            ['docker', 'inspect', '--format', '{{json .State}} {{json .NetworkSettings.Ports}}', $name],
            ['docker', 'exec', $name, 'curl', '--max-time', '2', '-v', 'http://127.0.0.1:8080/healthz'],
        ] as $command) {
            $diagnostic = new Process($command, timeout: 10);
            try {
                $diagnostic->run();
                fwrite(STDERR, $diagnostic->getOutput() . $diagnostic->getErrorOutput());
            } catch (Throwable $diagnosticError) {
                fwrite(STDERR, $diagnosticError->getMessage() . PHP_EOL);
            }
        }
    }
    throw $error;
} finally {
    if ($created) {
        if (($evidence = getenv('EVATHUMBER_SMOKE_EVIDENCE')) !== false) {
            $logs = new Process(['docker', 'logs', $name], timeout: 10);
            $logs->run();
            file_put_contents($evidence . '/' . $name . '.log', $logs->getOutput() . $logs->getErrorOutput());
        }
        $run(['docker', 'rm', '-f', $name]);
    }
}
