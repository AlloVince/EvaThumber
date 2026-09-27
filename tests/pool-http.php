<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
use Symfony\Component\Process\Process;

$image = $argv[1] ?? 'evathumber:pool-arm64';
$mode = $argv[2] ?? 'pool';
$name = 'eva-load-' . bin2hex(random_bytes(5));
$directory = sys_get_temp_dir() . '/' . $name;
mkdir($directory);
$run = static function (array $command): string {
    $p = new Process($command, timeout: 120); $p->mustRun(); return trim($p->getOutput());
};
$check = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$clients = [];
$samplers = [];
$created = false;
try {
    $command = ['docker', 'run', '-d', '--name', $name, '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
        '--memory', '512m', '--cpus', '2', '--pids-limit', '256', '--tmpfs', '/tmp', '--tmpfs', '/config/caddy:uid=33,gid=33',
        '--tmpfs', '/data/caddy:uid=33,gid=33', '--tmpfs', '/data/cache:uid=33,gid=33', '--tmpfs', '/data/images:uid=33,gid=33',
        '-e', 'EVATHUMBER_PHP_BINARY=/usr/local/bin/php', '-e', 'EVATHUMBER_TIMEOUT=3', '-e', 'EVATHUMBER_WORKER_RSS_MIB=192',
        '-p', '127.0.0.1::8080'];
    if ($mode === 'isolated') { array_push($command, '-e', 'EVATHUMBER_POOL_SOCKET=', '--entrypoint', '/usr/local/bin/frankenphp'); }
    $command[] = $image;
    if ($mode === 'isolated') { array_push($command, 'run', '--config', '/etc/frankenphp/Caddyfile', '--adapter', 'caddyfile'); }
    $run($command); $created = true;
    $base = 'http://' . $run(['docker', 'port', $name, '8080']);
    $run(['docker', 'exec', $name, 'php', '-r', 'require "/app/vendor/autoload.php"; $xy=\Jcupitt\Vips\Image::xyz(1800,1400); $x=$xy->extract_band(0); $y=$xy->extract_band(1); $x->multiply(.17)->sin()->add($y->multiply(.13)->cos())->multiply(60)->add(128)->bandjoin([$x->remainder(256),$y->remainder(256)])->cast("uchar")->copy(["interpretation"=>"srgb"])->jpegsave("/data/images/foo.jpg"); copy("/data/images/foo.jpg","/data/images/corrupt.jpg"); file_put_contents("/data/images/corrupt.jpg", substr(file_get_contents("/data/images/corrupt.jpg"),0,500));']);
    $request = static function (string $path) use ($base, $directory, &$clients): Process {
        $id = count($clients);
        $p = new Process(['curl', '--silent', '--show-error', '--max-time', '10', '--dump-header', $directory . '/h' . $id,
            '--output', $directory . '/b' . $id, '--write-out', '%{http_code} %{time_total}', $base . $path], timeout: 12);
        $clients[] = $p; return $p;
    };
    $one = static function (string $path) use ($request): array { $p = $request($path); $p->mustRun(); return explode(' ', $p->getOutput()); };
    $check($one('/healthz')[0] === '200', 'Health failed');
    foreach (['/image/upload/c_fill,w_300,h_300/foo.webp', '/image/upload/f_webp/foo.jpg', '/image/upload/c_fill,w_300/foo',
        '/image/upload/c_fill,w_300/v123/foo.webp', '/image/upload/c_fill,w_300/v123/foo.webp?_a=BAMAMid0&_i=test'] as $url) {
        $check($one($url)[0] === '200', 'Cloudinary URL failed: ' . $url);
        $id = count($clients) - 1;
        $size = getimagesize($directory . '/b' . $id);
        $check($size !== false, 'Not a decoded image');
        if (str_contains($url, 'webp')) { $check($size['mime'] === 'image/webp', 'Delivery format is not WebP'); }
    }
    $run(['docker', 'exec', $name, 'php', '-r', 'require "/app/vendor/autoload.php"; \Jcupitt\Vips\Image::black(10,10,["bands"=>3])->pngsave("/data/images/foo.png");']);
    $check($one('/image/upload/w_20/foo.webp')[0] === '409', 'Ambiguous public ID must be rejected');
    $run(['docker', 'exec', $name, 'rm', '/data/images/foo.png']);
    $hot = '/image/upload/w_200/foo.webp';
    $check($one($hot)[0] === '200', 'Warmup failed');
    $results = [];
    foreach (['hot', 'same', 'different', 'mixed'] as $scenario) {
        $before = $mode === 'pool' ? substr_count($run(['docker', 'logs', $name]), 'job_started') : 0;
        // docker logs keeps supervisor JSON on stderr, collect both streams below.
        $logsBefore = new Process(['docker', 'logs', $name]); $logsBefore->mustRun();
        $before = substr_count($logsBefore->getErrorOutput(), '"event":"job_started"');
        $samplerInput = new \Symfony\Component\Process\InputStream();
        // Frame the script as one line so PHP need not await stdin EOF to start;
        // the same open input then carries STOP after the measured workload.
        $sampler = new Process(['docker', 'exec', '-i', $name, 'php', '-r', 'eval(base64_decode(trim(fgets(STDIN))));'], input: $samplerInput, timeout: 20);
        $samplers[] = $sampler;
        $sampler->start();
        $samplerInput->write(base64_encode(substr(file_get_contents(__DIR__ . '/resource-sample.php'), 5)) . "\n");
        $check($sampler->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "READY\n")), 'Resource observer failed');
        $batch = []; $indices = []; $start = hrtime(true);
        for ($i = 0; $i < 40; ++$i) {
            $url = match ($scenario) {
                'hot' => $hot,
                'same' => '/image/upload/c_fill,w_900,h_700/f_webp,q_80/v900/foo.jpg',
                'different' => '/image/upload/c_fill,w_900,h_700/f_webp,q_80/v' . (1000 + $i) . '/foo.jpg',
                default => match ($i % 6) {
                    0 => $hot,
                    1 => '/image/upload/w_100/v' . (2000 + $i) . '/foo.jpg',
                    2 => '/image/upload/c_fill,w_400,h_300/f_webp/v' . (2000 + $i) . '/foo.jpg',
                    3 => '/image/upload/w_800/f_avif/v' . (2000 + $i) . '/foo.jpg',
                    4 => '/image/upload/w_1800/f_webp/v' . (2000 + $i) . '/foo.jpg',
                    5 => '/image/upload/w_800/q_auto/f_webp/v' . (2000 + $i) . '/foo.jpg',
                },
            };
            $p = $request($url); $indices[] = count($clients) - 1; $p->start(); $batch[] = $p;
        }
        $health = $one('/healthz'); $hit = $one($hot);
        $times = []; $codes = []; $hits = 0; $statusTimes = []; $hashes = []; $errors = [];
        foreach ($batch as $i => $p) {
            $check($p->wait() === 0, 'Transport error: ' . $p->getErrorOutput());
            [$code, $time] = explode(' ', $p->getOutput());
            $codes[$code] = ($codes[$code] ?? 0) + 1; $times[] = (float) $time * 1000;
            $hits += stripos(file_get_contents($directory . '/h' . $indices[$i]), 'x-evathumber-cache: HIT') !== false ? 1 : 0;
            $check(in_array($code, ['200', '503'], true), 'Unexpected HTTP ' . $code);
            $statusTimes[$code][] = (float) $time * 1000;
            if ($code === '200') {
                $check(getimagesize($directory . '/b' . $indices[$i]) !== false, 'Invalid cached output');
                $hashes[] = hash_file('sha256', $directory . '/b' . $indices[$i]);
            } else {
                $body = json_decode(file_get_contents($directory . '/b' . $indices[$i]), true, flags: JSON_THROW_ON_ERROR);
                $error = $body['error'] ?? 'unknown';
                $errors[$error] = ($errors[$error] ?? 0) + 1;
            }
        }
        sort($times); $elapsed = (hrtime(true) - $start) / 1e9;
        $samplerInput->write("STOP\n"); $samplerInput->close();
        $check($sampler->wait() === 0, 'Resource observer failed: ' . $sampler->getErrorOutput());
        $resources = json_decode(substr($sampler->getOutput(), strlen("READY\n")), true, flags: JSON_THROW_ON_ERROR);
        $latencies = [];
        foreach ($statusTimes as $code => $values) {
            sort($values);
            $percentile = static fn (float $p): float => $values[max(0, (int) ceil(count($values) * $p) - 1)];
            $latencies[$code] = ['p50_ms' => $percentile(.5), 'p95_ms' => $percentile(.95), 'p99_ms' => $percentile(.99)];
        }
        if ($scenario === 'same') { $check(count(array_unique($hashes)) === 1, 'Same-key output differs'); }
        $logsAfter = new Process(['docker', 'logs', $name]); $logsAfter->mustRun();
        $jobs = substr_count($logsAfter->getErrorOutput(), '"event":"job_started"') - $before;
        if ($scenario === 'same' && $mode === 'pool') { $check($jobs === 1, 'Same key generated ' . $jobs . ' times'); }
        $check($health[0] === '200' && $hit[0] === '200', 'Health/HIT lost under load');
        $results[$scenario] = ['requests' => 40, 'jobs' => $mode === 'pool' ? $jobs : null,
            'elapsed_seconds' => $elapsed, 'all_status_rps' => 40 / $elapsed, 'success_rps' => ($codes[200] ?? 0) / $elapsed,
            'latency_by_status' => $latencies, 'resources' => $resources,
            'codes' => $codes, 'errors' => $errors, 'hit_ratio' => $hits / 40, 'health_ms' => 1000 * (float) $health[1], 'hit_ms' => 1000 * (float) $hit[1]];
    }
    $check($one('/image/upload/w_200/corrupt.webp')[0] !== '200', 'Corrupt input unexpectedly succeeded');
    if ($mode === 'pool') {
        $logs = new Process(['docker', 'logs', $name]); $logs->mustRun();
        preg_match_all('/"event":"worker_start","slot":\d+,"pid":(\d+)/', $logs->getErrorOutput(), $matches);
        $pid = (int) end($matches[1]);
        $run(['docker', 'exec', $name, 'php', '-r', 'exit(posix_kill((int)$argv[1], SIGSTOP) ? 0 : 1);', (string) $pid]);
        // Both slots receive jobs; one is stopped and must be hard-killed on deadline.
        $a = $request('/image/upload/w_700/v7001/foo.webp'); $b = $request('/image/upload/w_700/v7002/foo.webp');
        $a->start(); $b->start(); $a->wait(); $b->wait();
        $codes = [substr($a->getOutput(), 0, 3), substr($b->getOutput(), 0, 3)];
        $check(in_array('504', $codes, true), 'Stopped worker did not reach a real hard timeout');
        $check($one('/healthz')[0] === '200', 'HTTP lost after timeout');
        $check($one('/image/upload/w_701/v7003/foo.webp')[0] === '200', 'No recovery after timeout');
        $livePid = $run(['docker', 'exec', $name, 'php', '-r', 'foreach (glob("/proc/[0-9]*/cmdline") as $file) { $cmd=@file_get_contents($file); if ($cmd !== false && str_contains($cmd, "process-guard.php") && str_contains($cmd, "/pool-worker.php")) { echo basename(dirname($file)); exit; } } exit(1);']);
        $run(['docker', 'exec', $name, 'php', '-r', 'exit(posix_kill((int)$argv[1], SIGKILL) ? 0 : 1);', $livePid]);
        $check($one('/healthz')[0] === '200', 'HTTP lost after SIGKILL');
        $check($one('/image/upload/w_702/v7004/foo.webp')[0] === '200', 'No recovery after SIGKILL');
    }
    $stats = $run(['docker', 'stats', '--no-stream', '--format', '{{json .}}', $name]);
    $run(['docker', 'stop', '--timeout', '30', $name]);
    $state = json_decode($run(['docker', 'inspect', '--format', '{{json .State}}', $name]), true, flags: JSON_THROW_ON_ERROR);
    $check($state['ExitCode'] === 0 && !$state['OOMKilled'], 'Unclean shutdown');
    $report = ['mode' => $mode, 'image' => $image, 'scenarios' => $results, 'idle_stats_only' => json_decode($stats, true), 'stop_exit' => $state['ExitCode']];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    if (($evidence = getenv('EVATHUMBER_EVIDENCE')) !== false) { file_put_contents($evidence, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"); }
} finally {
    foreach (array_merge($clients, $samplers) as $p) { if ($p->isRunning()) { $p->stop(0); } }
    if ($created) {
        $logs = new Process(['docker', 'logs', $name]); $logs->run();
        if (($evidence = getenv('EVATHUMBER_EVIDENCE')) !== false) { file_put_contents($evidence . '.log', $logs->getOutput() . $logs->getErrorOutput()); }
        $run(['docker', 'rm', '-f', $name]);
    }
    foreach (glob($directory . '/*') as $file) { unlink($file); } rmdir($directory);
}
