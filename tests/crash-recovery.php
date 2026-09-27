<?php

declare(strict_types=1);

// Destructive lifecycle acceptance: kill the service at the worst possible moment and
// prove the published cache is never wrong.
//
//   php tests/crash-recovery.php IMAGE [PLATFORM]
//
// Every scenario uses only the invocation the README documents — the image mount and the
// published port, no cache volume, no tuning flags. Each kill is proven to land mid-encode
// from the supervisor log (job_started outruns job_finished) and from the worker's private
// staging file, so a scenario cannot pass by accident on an idle pool. Every recovered
// product is byte-compared against a pristine container that never saw a crash.
require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Process\Process;

// libvips is guaranteed inside the image under test, not on the host running
// this script, so every decode is performed in the container.
$oracle = require __DIR__ . '/image-oracle.php';

$image = $argv[1] ?? throw new InvalidArgumentException('Usage: php tests/crash-recovery.php IMAGE [PLATFORM]');
$platform = $argv[2] ?? 'linux/arm64';
$work = sys_get_temp_dir() . '/eva-crash-' . bin2hex(random_bytes(5));
$containers = [];
$tails = [];

$try = static function (array $command, float $timeout = 120): array {
    $process = new Process($command, dirname(__DIR__), timeout: $timeout);
    $process->run();
    return ['code' => $process->getExitCode() ?? 1, 'out' => trim($process->getOutput()), 'err' => trim($process->getErrorOutput())];
};
$must = static function (array $command, float $timeout = 120) use ($try): string {
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
// Exactly the documented command line. Nothing here is a tuning knob a user would need.
$start = static function (string $name) use ($must, $work, $image, $platform, &$containers): string {
    $must(['docker', 'run', '-d', '--name', $name, '--platform', $platform,
        '-p', '127.0.0.1::8080', '-v', $work . '/images:/data/images:ro', $image]);
    $containers[] = $name;
    return 'http://' . $must(['docker', 'port', $name, '8080']);
};
// A readiness failure must say why. The pool supervisor reports its startup on the
// first log lines, which a bare `--tail` drops once polling has filled the buffer.
$logExcerpt = static function (string $name) use ($try): string {
    $logs = $try(['docker', 'logs', $name], 30);
    $lines = explode("\n", $logs['out'] . $logs['err']);
    return "--- log head ---\n" . implode("\n", array_slice($lines, 0, 40))
        . "\n--- log tail ---\n" . implode("\n", array_slice($lines, -25));
};
$probe = static function (string $url) use ($try): array {
    $result = $try(['curl', '--silent', '--show-error', '--max-time', '2', '--noproxy', '*',
        '--write-out', "\n%{http_code}", $url], 6);
    $split = strrpos($result['out'], "\n");
    return $split === false
        ? ['status' => 0, 'body' => $result['out']]
        : ['status' => (int) trim(substr($result['out'], $split + 1)), 'body' => trim(substr($result['out'], 0, $split))];
};
$awaitReady = static function (string $base, string $name = '') use ($probe, $check, $logExcerpt, $try): float {
    $started = hrtime(true);
    $live = ['status' => 0, 'body' => ''];
    $ready = ['status' => 0, 'body' => 'no probe reached the container'];
    do {
        $live = $probe($base . '/healthz');
        $ready = $probe($base . '/readyz');
        if ($live['status'] === 200 && $ready['status'] === 200) {
            return round((hrtime(true) - $started) / 1e6, 3);
        }
        usleep(100_000);
    } while (hrtime(true) - $started < 30_000_000_000);
    $check(false, 'Container never became ready at ' . $base
        . "\nlast healthz: " . json_encode($live, JSON_UNESCAPED_SLASHES)
        . "\nlast readyz: " . json_encode($ready, JSON_UNESCAPED_SLASHES)
        . ($name === '' ? '' : "\nstate: " . $try(['docker', 'inspect', '--format', '{{json .State}}', $name], 20)['out'] . "\n" . $logExcerpt($name)));
    return -1.0;
};
// OrbStack reassigns the ephemeral host port on every stop/start, so re-read it.
$rebase = static fn (string $name): string => 'http://' . $must(['docker', 'port', $name, '8080']);

// Stream the supervisor JSON log to a file so "is a worker busy right now" is answered by
// a local read instead of a process spawn per poll.
$tail = static function (string $name, string $file) use (&$tails): void {
    $process = Process::fromShellCommandline('docker logs -f --since 0s ' . escapeshellarg($name)
        . ' >> ' . escapeshellarg($file) . ' 2>&1', timeout: 1800);
    $process->start();
    $tails[] = $process;
};
$events = static function (string $file, string $event): int {
    $log = @file_get_contents($file);
    return $log === false ? 0 : substr_count($log, '"event":"' . $event . '"');
};
// Proof that a kill landed mid-encode: work was dispatched and had not finished.
$awaitEncoding = static function (string $name, string $file, int $started, int $finished) use ($events, $must): array {
    $began = hrtime(true);
    do {
        $nowStarted = $events($file, 'job_started');
        $nowFinished = $events($file, 'job_finished');
        // stage-N exists for exactly as long as a worker owns an encode.
        $staging = $must(['docker', 'exec', $name, 'sh', '-c', 'ls /tmp/evathumber | grep -c "^stage-" || true']);
        if ($nowStarted - $finished >= $started && $nowStarted > $nowFinished && trim($staging) !== '0') {
            return ['job_started' => $nowStarted, 'job_finished' => $nowFinished, 'staging_files' => (int) trim($staging),
                'waited_ms' => round((hrtime(true) - $began) / 1e6, 3)];
        }
        usleep(20_000);
    } while (hrtime(true) - $began < 20_000_000_000);
    throw new RuntimeException('No encode was ever in flight in ' . $name);
};
$pidOf = static function (string $name, string $needle) use ($must): string {
    $script = 'foreach (glob("/proc/[0-9]*/cmdline") as $file) { $cmd = @file_get_contents($file);'
        . ' if ($cmd !== false && str_contains($cmd, $argv[1]) && !str_contains($cmd, $argv[2])) { echo basename(dirname($file)); exit; } } exit(1);';
    return $must(['docker', 'exec', $name, 'php', '-r', $script, $needle, $needle . '-worker']);
};
$fetch = static function (string $base, string $path, string $file, float $timeout = 60) use ($try): array {
    $result = $try(['curl', '--silent', '--show-error', '--max-time', (string) $timeout, '--noproxy', '*',
        '--dump-header', $file . '.head', '--output', $file, '--write-out', '%{http_code} %{size_download}', $base . $path], $timeout + 15);
    if ($result['code'] !== 0) {
        return ['status' => 0, 'bytes' => 0, 'error' => trim($result['err'] . ' ' . $result['out'])];
    }
    [$status, $size] = array_pad(explode(' ', $result['out']), 2, '0');
    $head = (string) @file_get_contents($file . '.head');
    return ['status' => (int) $status, 'bytes' => (int) $size, 'error' => '',
        'cache' => preg_match('/^x-evathumber-cache:\s*(\w+)/mi', $head, $match) === 1 ? $match[1] : null,
        'etag' => preg_match('/^etag:\s*"?([0-9a-f]+)"?/mi', $head, $match) === 1 ? $match[1] : null,
        'sha256' => (int) $status === 200 ? hash_file('sha256', $file) : null];
};
// A bounded 503 is correct designed behaviour, not a crash defect, so reference and
// recovery reads may retry it. The kill assertions below never retry anything.
$settled = static function (string $base, string $path, string $file) use ($fetch, $check): array {
    for ($attempt = 1; ; ++$attempt) {
        $response = $fetch($base, $path, $file);
        if ($response['status'] === 200 || $response['status'] === 0 || $attempt >= 4) {
            return $response;
        }
        usleep(500_000);
    }
};
// libvips keeps a per-inode descriptor mapped, so re-reading a path that curl rewrote in
// place can return the previous image's header. Every read here is therefore buffer-based
// and every scratch path is used once.
$serial = 0;
$scratch = static function (string $label) use (&$serial, $work): string {
    return $work . '/' . $label . '-' . $serial++;
};
$started = [];
$fire = static function (string $base, array $paths) use (&$started, $scratch): void {
    $started = [];
    foreach ($paths as $path) {
        $body = $scratch('fire');
        $process = new Process(['curl', '--silent', '--show-error', '--max-time', '45', '--noproxy', '*',
            '--dump-header', $body . '.head', '--output', $body, '--write-out', '%{http_code} %{size_download}', $base . $path], timeout: 60);
        $process->start();
        $started[] = ['process' => $process, 'body' => $body, 'path' => $path];
    }
};
// A 200 must be a complete, decodable image. A crash may only ever remove work, never
// publish a partial product, so a truncated 200 is a hard failure.
$settle = static function () use (&$started): array {
    $results = [];
    foreach ($started as $request) {
        $process = $request['process'];
        $process->wait();
        $output = trim($process->getOutput());
        [$status, $size] = $output === '' ? ['0', '0'] : array_pad(explode(' ', $output), 2, '0');
        $status = (int) $status;
        $results[] = ['path' => $request['path'], 'status' => $status, 'bytes' => (int) $size,
            'error' => $status === 0 ? trim($process->getErrorOutput()) : ''];
        if ($status === 200) {
            $decoded = @getimagesize($request['body']);
            if ($decoded === false) {
                throw new RuntimeException('A 200 response is not a decodable image: ' . $request['path']);
            }
            $results[array_key_last($results)]['decoded'] = $decoded[0] . 'x' . $decoded[1];
        }
    }
    $started = [];
    return $results;
};
$residue = static function (string $name) use ($must): array {
    $listing = static fn (string $path, string $pattern): int => (int) $must(['docker', 'exec', $name, 'sh',
        '-c', 'ls -a ' . $path . ' | grep -c "' . $pattern . '" || true']);
    return ['pool_staging' => $listing('/tmp/evathumber', '^stage-'), 'cache_temporaries' => $listing('/data/cache', '^\.tmp-')];
};
// A recovered product must be indistinguishable from one built by a container that never
// crashed. Anything else means the cache survived the kill in a wrong state.
$recover = static function (string $name, string $base, array $reference, array $paths) use ($check, $awaitReady, $settled, $residue, $scratch, $oracle): array {
    $awaitReady($base, $name);
    $recovered = ['products' => [], 'residue' => null];
    foreach ($paths as $path) {
        $file = $scratch('recovered');
        $response = $settled($base, $path, $file);
        $check($response['status'] === 200, 'Recovery must return 200 for ' . $path . ', got ' . $response['status'] . ' ' . $response['error']);
        [$decodedWidth, $decodedHeight] = $oracle($name, (string) file_get_contents($file));
        $check($decodedWidth === $reference[$path]['width'] && $decodedHeight === $reference[$path]['height'],
            'Recovered dimensions differ for ' . $path . ': got ' . $decodedWidth . 'x' . $decodedHeight
            . ' want ' . $reference[$path]['width'] . 'x' . $reference[$path]['height']
            . ' (cache=' . var_export($response['cache'], true) . ' sha=' . var_export($response['sha256'], true) . ')');
        $check($response['sha256'] === $reference[$path]['sha256'],
            'Recovered bytes differ from a pristine container for ' . $path
            . ' (crash=' . $response['sha256'] . ' clean=' . $reference[$path]['sha256'] . ')');
        $second = $settled($base, $path, $scratch('replayed'));
        $check($second['cache'] === 'HIT' && $second['sha256'] === $response['sha256'], 'Recovered product must then HIT identically: ' . $path);
        $recovered['products'][$path] = ['status' => $response['status'], 'sha256' => $response['sha256'],
            'width' => $decodedWidth, 'height' => $decodedHeight, 'cache' => $response['cache'], 'replay_cache' => $second['cache']];
    }
    $leftovers = $residue($name);
    $check($leftovers['pool_staging'] === 0, 'Abandoned worker staging survived recovery: ' . json_encode($leftovers));
    $check($leftovers['cache_temporaries'] === 0, 'Abandoned cache temporary survived recovery: ' . json_encode($leftovers));
    $recovered['residue'] = $leftovers;
    return $recovered;
};
$restart = static function (string $name) use ($must, $check, $try, $rebase): string {
    $must(['docker', 'start', $name], 120);
    $deadline = hrtime(true) + 30_000_000_000;
    do {
        if ($try(['docker', 'inspect', '--format', '{{.State.Running}}', $name])['out'] === 'true') {
            break;
        }
        usleep(100_000);
    } while (hrtime(true) < $deadline);
    $check($try(['docker', 'inspect', '--format', '{{.State.Running}}', $name])['out'] === 'true', 'Container did not restart: ' . $name);
    return $rebase($name);
};

// One key pair per scenario, so no scenario can be satisfied by another's cache entry.
// One key pair per scenario, so no scenario can be satisfied by another's cache entry.
// Every key also gets its own output size, so a product published under the wrong key
// would be visibly wrong instead of accidentally matching the reference bytes.
$scenarios = [
    'busy_worker_sigkill' => [['c_fill,w_2000,h_1500', 'v9101'], ['c_fill,w_1993,h_1494', 'v9102']],
    'container_sigkill' => [['c_fill,w_1986,h_1489', 'v9201'], ['c_fill,w_1979,h_1484', 'v9202']],
    'pool_supervisor_sigkill' => [['c_fill,w_1972,h_1479', 'v9301'], ['c_fill,w_1965,h_1473', 'v9302']],
    'graceful_stop_inflight' => [['c_fill,w_1958,h_1468', 'v9401'], ['c_fill,w_1951,h_1463', 'v9402']],
];
$chain = static fn (string $transform, string $version): string
    => '/image/upload/' . $transform . '/q_auto:best/f_webp/' . $version . '/big.jpg';
$report = [];

try {
    // Readable by any uid: the container runs as www-data (33) while this directory is
    // owned by whoever runs the suite, and a 0700 bind mount is unreadable to it on real
    // Linux. OrbStack remaps mount ownership to the container user, which hides this.
    if (!is_dir($work . '/images') && !mkdir($work . '/images', 0755, true)) {
        throw new RuntimeException('Cannot create the fixture directory.');
    }
    chmod($work . '/images', 0755);
    // A large enough source that a cold derivation is long enough to be killed inside.
    // Generated by a one-shot container because libvips is only guaranteed in the image.
    $must(['docker', 'run', '--rm', '--platform', $platform, '-v', $work . '/images:/out',
        '--entrypoint', 'php', $image, '-r',
        'require "/app/vendor/autoload.php";'
        . '$xy = \Jcupitt\Vips\Image::xyz(4000, 3000); $r = $xy->extract_band(0); $g = $xy->extract_band(1);'
        . '$r->multiply(.17)->sin()->add($g->multiply(.13)->cos())->multiply(60)->add(128)'
        . '->bandjoin([$r->remainder(256), $g->remainder(256)])->cast("uchar")'
        . '->copy(["interpretation" => "srgb"])->jpegsave("/out/big.jpg", ["Q" => 92]);'], 180);
    $check(is_file($work . '/images/big.jpg'), 'The container did not produce the source fixture.');
    $facts = ['image' => $must(['docker', 'image', 'inspect', '--format', '{{.Id}}', $image]),
        'architecture' => $must(['docker', 'image', 'inspect', '--format', '{{.Architecture}}', $image]),
        'source' => ['file' => 'big.jpg', 'sha256' => hash_file('sha256', $work . '/images/big.jpg'),
            'bytes' => filesize($work . '/images/big.jpg'), 'width' => 4000, 'height' => 3000],
        'delivery' => 'q_auto:best/f_webp'];

    // Pristine reference: what the product looks like when nothing ever goes wrong.
    $referenceName = 'eva-crash-ref-' . bin2hex(random_bytes(4));
    $referenceBase = $start($referenceName);
    $awaitReady($referenceBase, $referenceName);
    $reference = [];
    foreach ($scenarios as $keys) {
        foreach ($keys as [$transform, $version]) {
            $path = $chain($transform, $version);
            $file = $scratch('reference');
            $response = $settled($referenceBase, $path, $file);
            $check($response['status'] === 200, 'Reference derivation failed for ' . $path . ': ' . $response['error']);
            [$decodedWidth, $decodedHeight] = $oracle($referenceName, (string) file_get_contents($file));
            $reference[$path] = ['sha256' => $response['sha256'], 'width' => $decodedWidth, 'height' => $decodedHeight];
        }
    }
    $must(['docker', 'rm', '-f', $referenceName]);
    // Without this the byte comparison could not tell one product from another.
    $check(count(array_unique(array_column($reference, 'sha256'))) === count($reference),
        'Reference products must be mutually distinct for the byte comparison to mean anything');
    $facts['reference_products'] = count($reference);

    $name = 'eva-crash-' . bin2hex(random_bytes(4));
    $base = $start($name);
    $facts['initial_ready_ms'] = $awaitReady($base, $name);
    $log = $work . '/pool.log';
    $tail($name, $log);

    foreach ($scenarios as $scenario => $keys) {
        $paths = array_map(static fn (array $key): string => $chain($key[0], $key[1]), $keys);
        $before = ['started' => $events($log, 'job_started'), 'finished' => $events($log, 'job_finished')];
        $fire($base, $paths);
        $inFlight = $awaitEncoding($name, $log, 2, $before['finished']);
        $entry = ['paths' => $paths, 'in_flight' => $inFlight];

        if ($scenario === 'busy_worker_sigkill') {
            // Kill a worker that is demonstrably encoding right now.
            $entry['killed_pid'] = $pidOf($name, '/app/bin/pool-worker.php');
            $must(['docker', 'exec', $name, 'php', '-r', 'exit(posix_kill((int) $argv[1], SIGKILL) ? 0 : 1);', $entry['killed_pid']]);
            $entry['responses'] = $settle();
            foreach ($entry['responses'] as $response) {
                $check($response['status'] !== 200 || isset($response['decoded']),
                    'A killed worker must never yield an undecodable 200: ' . $response['path']);
            }
            $check($try(['curl', '-fsS', '--max-time', '3', '--noproxy', '*', $base . '/healthz'], 10)['code'] === 0,
                'A busy worker kill must not take HTTP down');
            $entry['healthz_after_kill'] = 200;
            $entry['recovery'] = $recover($name, $base, $reference, $paths);
        } elseif ($scenario === 'container_sigkill') {
            // Hard kill of PID 1 while both workers encode: no signal handler can run.
            $try(['docker', 'kill', '--signal', 'KILL', $name], 60);
            $entry['responses'] = $settle();
            foreach ($entry['responses'] as $response) {
                $check($response['status'] !== 200, 'A SIGKILLed container cannot answer 200: ' . $response['path']);
            }
            $base = $restart($name);
            $entry['restart_ready_ms'] = $facts['initial_ready_ms'] = $awaitReady($base, $name);
            $entry['recovery'] = $recover($name, $base, $reference, $paths);
        } elseif ($scenario === 'pool_supervisor_sigkill') {
            // Parent sudden death: PID 1 must reap the tree and the container must exit.
            $entry['killed_pid'] = $pidOf($name, '/app/bin/pool.php');
            $must(['docker', 'exec', $name, 'php', '-r', 'exit(posix_kill((int) $argv[1], SIGKILL) ? 0 : 1);', $entry['killed_pid']]);
            $entry['responses'] = $settle();
            foreach ($entry['responses'] as $response) {
                $check($response['status'] !== 200 || isset($response['decoded']),
                    'A killed supervisor must never yield an undecodable 200: ' . $response['path']);
            }
            $deadline = hrtime(true) + 30_000_000_000;
            do {
                $state = json_decode($must(['docker', 'inspect', '--format', '{{json .State}}', $name]), true, flags: JSON_THROW_ON_ERROR);
                if (!$state['Running']) {
                    break;
                }
                usleep(100_000);
            } while (hrtime(true) < $deadline);
            $check(!$state['Running'], 'Losing the pool supervisor must stop the container');
            $entry['exit_code'] = $state['ExitCode'];
            $entry['oom_killed'] = $state['OOMKilled'];
            $base = $restart($name);
            $entry['restart_ready_ms'] = $facts['initial_ready_ms'] = $awaitReady($base, $name);
            $entry['recovery'] = $recover($name, $base, $reference, $paths);
        } else {
            // Graceful stop with real work in flight: drain or bounded failure, never a
            // half-written product, and a clean exit code.
            $stopped = hrtime(true);
            $must(['docker', 'stop', '--timeout', '30', $name], 90);
            $entry['stop_ms'] = round((hrtime(true) - $stopped) / 1e6, 3);
            $entry['responses'] = $settle();
            foreach ($entry['responses'] as $response) {
                $check($response['status'] !== 200 || isset($response['decoded']),
                    'A graceful stop must never yield an undecodable 200: ' . $response['path']);
            }
            $state = json_decode($must(['docker', 'inspect', '--format', '{{json .State}}', $name]), true, flags: JSON_THROW_ON_ERROR);
            $check(!$state['OOMKilled'], 'Graceful stop must not be an OOM kill');
            $check($state['ExitCode'] === 0, 'Graceful stop must exit 0, got ' . $state['ExitCode']);
            $entry['exit_code'] = $state['ExitCode'];
            $base = $restart($name);
            $entry['restart_ready_ms'] = $facts['initial_ready_ms'] = $awaitReady($base, $name);
            $entry['recovery'] = $recover($name, $base, $reference, $paths);
        }
        $report[$scenario] = $entry;
    }

    // The container survives all of it and still serves, including a fresh cold miss.
    $after = $chain('c_fill,w_1944,h_1458', 'v9501');
    $final = $settled($base, $after, $scratch('final'));
    $check($final['status'] === 200, 'The container must still serve after every crash scenario.');
    $report['after_all_scenarios'] = ['path' => $after, 'status' => $final['status'],
        'sha256' => $final['sha256'], 'cache' => $final['cache']];
    $state = json_decode($must(['docker', 'inspect', '--format', '{{json .State}}', $name]), true, flags: JSON_THROW_ON_ERROR);
    $must(['docker', 'stop', '--timeout', '30', $name], 60);
    $finalState = json_decode($must(['docker', 'inspect', '--format', '{{json .State}}', $name]), true, flags: JSON_THROW_ON_ERROR);
    $check($finalState['ExitCode'] === 0 && !$finalState['OOMKilled'], 'Final stop must be clean: ' . json_encode($finalState));
    $report['final_stop'] = ['exit_code' => $finalState['ExitCode'], 'oom_killed' => $finalState['OOMKilled']];

    echo json_encode(['platform' => $platform, 'result' => 'passed'] + $facts + ['scenarios' => $report],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    foreach ($containers as $container) {
        fwrite(STDERR, $logExcerpt($container) . "\n");
        if ($logs['err'] !== '' || $logs['out'] !== '') {
            fwrite(STDERR, $logs['out'] . $logs['err'] . "\n");
        }
    }
    throw $error;
} finally {
    foreach ($tails as $tail) {
        if ($tail->isRunning()) {
            $tail->stop(1);
        }
    }
    foreach ($containers as $container) {
        $try(['docker', 'rm', '-f', $container], 90);
    }
    foreach (glob($work . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    @rmdir($work . '/images');
    @rmdir($work);
}
