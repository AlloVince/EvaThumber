<?php

declare(strict_types=1);

/**
 * Local-only, closed-loop sustained HTTP benchmark; no application mutations.
 * php bench/http-load.php --build --out=bench/results/session --seconds=8 --rounds=3 --concurrency=4,16
 * Quick diagnostic: --seconds=12 --rounds=1 --concurrency=2 --scenarios=mixed
 * Optional controlled variants: --workers=1 --worker-max-jobs=1 (not production defaults).
 * Every run uses fresh disposable containers and read-only copies of existing upload fixtures.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Jcupitt\Vips\Image;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

function command(array $args, ?float $timeout = 120): string
{
    $p = new Process($args, dirname(__DIR__), timeout: $timeout);
    $p->mustRun();
    return trim($p->getOutput());
}

function jsonSave(string $path, mixed $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

function snapshot(): array
{
    $root = dirname(__DIR__);
    $files = ['Dockerfile', '.dockerignore', 'composer.json', 'composer.lock', 'docker/Caddyfile'];
    foreach (['src', 'public', 'bin'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') { $files[] = substr($file->getPathname(), strlen($root) + 1); }
        }
    }
    sort($files);
    $hashes = [];
    foreach ($files as $file) { $hashes[$file] = hash_file('sha256', $root . '/' . $file); }
    $diff = command(['git', 'diff', '--binary', 'HEAD', '--', 'Dockerfile', '.dockerignore', 'composer.json', 'composer.lock', 'src', 'public', 'bin', 'docker/Caddyfile']);
    return ['commit' => command(['git', 'rev-parse', 'HEAD']), 'production_tree_sha256' => hash('sha256', json_encode($hashes)),
        'production_file_sha256' => $hashes, 'tracked_production_diff_sha256' => hash('sha256', $diff),
        'git_status' => command(['git', 'status', '--short']), 'driver_sha256' => hash_file('sha256', __FILE__)];
}

function percentiles(array $values): array
{
    if ($values === []) { return ['count' => 0]; }
    sort($values);
    $out = ['count' => count($values)];
    foreach ([50, 95, 99] as $p) { $out['p' . $p . '_ms'] = round($values[max(0, (int) ceil(count($values) * $p / 100) - 1)], 3); }
    $out['max_ms'] = round(max($values), 3);
    return $out;
}

function observerCode(): string
{
    return <<<'PHP'
stream_set_blocking(STDIN, false);
$start = hrtime(true); $rows = []; $peaks = []; $cpuStart = null;
$stat = static function (): array {
    $out = []; foreach (explode("\n", trim(file_get_contents('/sys/fs/cgroup/cpu.stat'))) as $line) {
        [$key, $value] = explode(' ', $line); $out[$key] = (int) $value;
    } return $out;
};
$cpuStart = $stat();
echo "READY\n"; fflush(STDOUT);
do {
    $rssTotal = 0; $roles = []; $workers = [];
    foreach (glob('/proc/[0-9]*/status') as $file) {
        $pid = (int) basename(dirname($file)); if ($pid === getmypid()) { continue; }
        $text = @file_get_contents($file);
        if ($text === false || !preg_match('/VmRSS:\s+(\d+)/', $text, $m)) { continue; }
        $rss = (int) $m[1] * 1024; $rssTotal += $rss;
        $cmd = str_replace("\0", ' ', (string) @file_get_contents(dirname($file) . '/cmdline'));
        $role = str_contains($cmd, '/pool-worker.php') ? 'pool_worker' : (str_contains($cmd, '/transform.php') ? 'isolated_worker' : (str_contains($cmd, '/pool.php') ? 'pool_supervisor' : (str_contains($cmd, 'frankenphp') ? 'http' : 'other')));
        $proc = (string) @file_get_contents(dirname($file) . '/stat'); $fields = explode(' ', substr($proc, (int) strrpos($proc, ')') + 2));
        $ticks = (int) ($fields[11] ?? 0) + (int) ($fields[12] ?? 0);
        $roles[$role]['rss_bytes'] = ($roles[$role]['rss_bytes'] ?? 0) + $rss;
        if (!isset($peaks[$pid])) { $peaks[$pid] = ['role' => $role, 'rss_peak_bytes' => 0, 'first_cpu_ticks' => $ticks, 'last_cpu_ticks' => $ticks]; }
        $peaks[$pid]['rss_peak_bytes'] = max($rss, $peaks[$pid]['rss_peak_bytes']); $peaks[$pid]['last_cpu_ticks'] = $ticks;
        if (str_contains($role, 'worker')) { $workers[$pid] = $rss; }
    }
    $rows[] = ['seconds' => round((hrtime(true) - $start) / 1e9, 3), 'cpu' => $stat(),
        'cgroup_memory_bytes' => (int) file_get_contents('/sys/fs/cgroup/memory.current'),
        'process_rss_bytes' => $rssTotal, 'roles' => $roles, 'worker_rss' => (object) $workers];
    $read = [STDIN]; $write = $except = [];
    if (stream_select($read, $write, $except, 0, 100000) > 0) { break; }
} while (hrtime(true) - $start < 120000000000);
$last = $stat();
echo json_encode(['cpu_seconds' => ($last['usage_usec'] - $cpuStart['usage_usec']) / 1e6,
    'cpu_throttled_seconds' => ($last['throttled_usec'] - $cpuStart['throttled_usec']) / 1e6,
    'observer_seconds' => (hrtime(true) - $start) / 1e9, 'sample_interval_ms' => 100,
    'cgroup_memory_peak_bytes' => max(array_column($rows, 'cgroup_memory_bytes')),
    'process_rss_peak_bytes' => max(array_column($rows, 'process_rss_bytes')),
    'processes' => (object) $peaks, 'samples' => $rows,
    'notes' => 'Sampled peaks miss transients; summed RSS double counts shared pages. Cgroup includes tmpfs/cache and observer. Process tick deltas miss short lived processes and first interval; cgroup CPU is authoritative.']) . "\n";
PHP;
}

function poolEvents(string $name): array
{
    $p = new Process(['docker', 'logs', $name]); $p->mustRun();
    $events = [];
    foreach (explode("\n", $p->getErrorOutput() . "\n" . $p->getOutput()) as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && isset($row['event'])) { $events[] = $row; }
    }
    return $events;
}

function workload(string $scenario, int $sequence, int $version): array
{
    $hot = '/image/upload/w_100/f_webp/demo.jpg';
    if ($scenario === 'hot') { return [$hot, 'hot', 100, null, 'image/webp']; }
    if ($scenario === 'same') { return ['/image/upload/c_fill,w_900,h_700/f_webp,q_80/v' . $version . '/large.jpg', 'same', 900, 700, 'image/webp']; }
    if ($scenario === 'different') { return ['/image/upload/c_fill,w_900,h_700/f_webp,q_80/v' . ($version + $sequence) . '/large.jpg', 'different', 900, 700, 'image/webp']; }
    $v = '/v' . ($version + $sequence) . '/';
    return match ($sequence % 7) {
        0 => [$hot, 'hot', 100, null, 'image/webp'],
        1 => ['/image/upload/w_100' . $v . 'face.jpg', 'small_resize', 100, null, 'image/jpeg'],
        2 => ['/image/upload/c_fill,w_240,h_160/f_webp' . $v . 'blend.png', 'crop_webp', 240, 160, 'image/webp'],
        3 => ['/image/upload/w_800/f_avif' . $v . 'large.jpg', 'avif', 800, null, 'image/avif'],
        4 => ['/image/upload/w_2400/f_webp' . $v . 'large.jpg', 'large_webp', 2400, null, 'image/webp'],
        5 => ['/image/upload/w_800/q_auto/f_webp' . $v . 'large.jpg', 'q_auto_webp', 800, null, 'image/webp'],
        6 => ['/image/upload/w_180/f_webp' . $v . 'demo.jpg', 'small_webp', 180, null, 'image/webp'],
    };
}

function measuredLoad(string $base, string $scenario, int $concurrency, float $seconds, int $version, string $directory): array
{
    $multi = curl_multi_init(); $active = []; $rows = []; $bodies = []; $failures = []; $seq = 0; $loadSequence = 0;
    $start = hrtime(true) / 1e9; $deadline = $start + $seconds; $probeDue = ['health' => $start, 'hit' => $start]; $probeActive = [];
    $launch = static function (string $kind, int $slot) use ($multi, &$active, &$seq, &$loadSequence, $scenario, $version, $base, $start): void {
        $index = $seq++;
        $spec = $kind === 'health' ? ['/healthz', 'health', null, null, 'application/json'] : ($kind === 'hit' ? workload('hot', 0, 0) : workload($scenario, $loadSequence++, $version));
        $ch = curl_init($base . $spec[0]);
        $state = (object) ['headers' => [], 'spec' => $spec, 'kind' => $kind, 'slot' => $slot, 'index' => $index,
            'launch_s' => hrtime(true) / 1e9 - $start];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 20000, CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use ($state): int {
                if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $state->headers[strtolower(trim($key))] = trim($value); }
                return strlen($line);
            }]);
        $active[spl_object_id($ch)] = $state; curl_multi_add_handle($multi, $ch);
    };
    for ($i = 0; $i < $concurrency; ++$i) { $launch('load', $i); }
    do {
        $now = hrtime(true) / 1e9;
        foreach ($probeDue as $kind => $due) {
            if ($now < $deadline && $now >= $due && !isset($probeActive[$kind])) { $launch($kind, -1); $probeActive[$kind] = true; $probeDue[$kind] = $now + .5; }
        }
        do { $result = curl_multi_exec($multi, $running); } while ($result === CURLM_CALL_MULTI_PERFORM);
        if ($result !== CURLM_OK) { throw new RuntimeException('curl multi error ' . $result); }
        while (($done = curl_multi_info_read($multi)) !== false) {
            $ch = $done['handle']; $state = $active[spl_object_id($ch)]; $info = curl_getinfo($ch);
            $body = (string) curl_multi_getcontent($ch); $code = (int) $info['http_code']; $headers = $state->headers;
            $error = $done['result'] !== CURLE_OK ? curl_error($ch) : null;
            if ($code !== 200 && $error === null) { $error = json_decode($body, true)['error'] ?? 'non_json_error'; }
            $hash = null;
            if ($code === 200 && $state->kind !== 'health') {
                $hash = hash('sha256', $body);
                if (!isset($bodies[$hash])) { file_put_contents($directory . '/' . $hash, $body); $bodies[$hash] = $state->spec; }
                if (($headers['content-type'] ?? '') !== $state->spec[4]) { $failures[] = 'MIME mismatch: ' . $state->spec[0]; }
            }
            if ($state->kind === 'health' && ($code !== 200 || $error !== null)) { $failures[] = 'Health probe failed'; }
            if ($state->kind === 'hit' && ($code !== 200 || ($headers['x-evathumber-cache'] ?? null) !== 'HIT')) { $failures[] = 'HIT probe failed'; }
            if ($state->kind === 'load' && (!in_array($code, [200, 503], true) || $done['result'] !== CURLE_OK)) { $failures[] = 'Unexpected load response: ' . $code . ' ' . $error; }
            $rows[] = ['kind' => $state->kind, 'i' => $state->index, 'slot' => $state->slot, 'class' => $state->spec[1],
                'launch_s' => round($state->launch_s, 6), 'finish_s' => round(hrtime(true) / 1e9 - $start, 6),
                'status' => $code, 'ms' => round($info['total_time'] * 1000, 3), 'ttfb_ms' => round($info['starttransfer_time'] * 1000, 3),
                'connect_ms' => round($info['connect_time'] * 1000, 3), 'cache' => $headers['x-evathumber-cache'] ?? null,
                'bytes' => strlen($body), 'error' => $error, 'sha256' => $hash];
            curl_multi_remove_handle($multi, $ch); unset($active[spl_object_id($ch)]);
            if ($state->kind === 'load' && hrtime(true) / 1e9 < $deadline) { $launch('load', $state->slot); }
            if ($state->kind !== 'load') { unset($probeActive[$state->kind]); }
        }
        if ($active !== []) { if (curl_multi_select($multi, .01) === -1) { usleep(1000); } }
    } while ($active !== [] || hrtime(true) / 1e9 < $deadline);
    $elapsed = hrtime(true) / 1e9 - $start;
    curl_multi_close($multi);
    return ['rows' => $rows, 'bodies' => $bodies, 'failures' => $failures, 'elapsed_seconds' => $elapsed];
}

$options = getopt('', ['build', 'image:', 'out:', 'seconds:', 'rounds:', 'concurrency:', 'scenarios:', 'modes:', 'workers:', 'worker-max-jobs:', 'source-storage:', 'curl-diagnostic']);
$image = $options['image'] ?? 'evathumber:session-arm64';
$out = $options['out'] ?? ('bench/results/' . gmdate('Ymd-His'));
if (!str_starts_with($out, '/')) { $out = dirname(__DIR__) . '/' . $out; }
$seconds = (float) ($options['seconds'] ?? 8); $rounds = (int) ($options['rounds'] ?? 3);
$concurrencies = array_map('intval', explode(',', $options['concurrency'] ?? '4,16'));
$scenarios = explode(',', $options['scenarios'] ?? 'hot,same,different,mixed'); $modes = explode(',', $options['modes'] ?? 'pool,isolated');
if ($seconds < 1 || $seconds > 30 || $rounds < 1 || $rounds > 3 || min($concurrencies) < 1 || max($concurrencies) > 16
    || array_diff($scenarios, ['hot', 'same', 'different', 'mixed']) !== [] || array_diff($modes, ['pool', 'isolated']) !== []) {
    throw new InvalidArgumentException('Bounds: seconds 1..30, rounds 1..3, concurrency 1..16; known local modes/scenarios only.');
}
if (file_exists($out)) { throw new RuntimeException('Evidence directory already exists; use a new --out path.'); }
mkdir($out, 0770, true);
$initial = snapshot(); jsonSave($out . '/initial-source.json', $initial);
$report = ['started_utc' => gmdate(DATE_ATOM), 'options' => $options, 'image_tag' => $image, 'initial_source' => $initial['production_tree_sha256'],
    'method' => ['closed_loop' => true, 'seconds_per_scenario' => $seconds, 'concurrencies' => $concurrencies, 'rounds' => $rounds,
        'probes' => 'Two extra independent health/HIT lanes every >=500ms; not included in load concurrency or throughput.',
        'rate' => 'success_rps counts completed 200 within the fixed measurement window; drained_success_rps includes final in-flight drain.',
        'same' => 'One initially cold key per scenario, then naturally HIT; jobs exactly 1 across the entire interval. Not sustained cold misses.',
        'cold' => 'Different/mixed version segment is unique for every cold request, not an ignored query parameter.',
        'decode' => 'Full libvips pixel evaluation once per unique response SHA256 after timing; dimensions/MIME validated; every successful response hashed.',
        'limits' => ['memory_mib' => 512, 'cpus' => 2, 'pids' => 256, 'http_workers' => 16, 'timeout_seconds' => 15],
        'rejection_phase' => 'Pool log errors prove pool rejection counts; residual HTTP errors are pre-pool/HTTP/cache, without unsupported exact lock-phase attribution.',
        'fixtures_caveat' => 'Original local 300px photographs plus a deliberately enlarged derivative, not genuine native large-photo evidence. No downloaded fixtures.'],
    'runs' => [], 'failures' => []];
$temporary = sys_get_temp_dir() . '/eva-sustained-' . bin2hex(random_bytes(5)); mkdir($temporary); mkdir($temporary . '/source');
$name = null; $sampler = null; $input = null;
try {
    if (isset($options['build'])) {
        $build = new Process(['docker', 'build', '--platform', 'linux/arm64', '--target', 'production', '-t', $image, '.'], dirname(__DIR__), timeout: 1200);
        $build->run(); file_put_contents($out . '/build.log', $build->getOutput() . $build->getErrorOutput());
        if (!$build->isSuccessful()) { throw new RuntimeException('Production build failed; see build.log'); }
        $afterBuild = snapshot(); jsonSave($out . '/after-build-source.json', $afterBuild);
        $report['source_changed_during_build'] = $initial['production_tree_sha256'] !== $afterBuild['production_tree_sha256'];
    }
    $inspect = json_decode(command(['docker', 'image', 'inspect', $image]), true, flags: JSON_THROW_ON_ERROR)[0];
    $imageId = $inspect['Id']; $report['image_id'] = $imageId;
    $report['environment'] = ['host_os' => php_uname(), 'host_php' => PHP_VERSION, 'curl' => curl_version(),
        'docker' => json_decode(command(['docker', 'info', '--format', '{{json .}}']), true)['OperatingSystem'] ?? null,
        'docker_limits' => command(['docker', 'info', '--format', 'OS={{.OSType}} Arch={{.Architecture}} CPUs={{.NCPU}} Memory={{.MemTotal}}']),
        'image_arch' => $inspect['Architecture'], 'image_created' => $inspect['Created'], 'image_env' => $inspect['Config']['Env']];
    // Pin execution to immutable image ID, even if another session retags the image.
    $manifestCode = '$files=json_decode($argv[1],true); $out=[]; foreach($files as $f){if(in_array($f,["Dockerfile",".dockerignore"]))continue; $p=$f==="docker/Caddyfile"?"/etc/frankenphp/Caddyfile":"/app/".$f; $out[$f]=is_file($p)?hash_file("sha256",$p):null;}echo json_encode($out);';
    $builtFiles = json_decode(command(['docker', 'run', '--rm', '--network', 'none', '--entrypoint', 'php', $imageId, '-r', $manifestCode,
        json_encode(array_keys($initial['production_file_sha256']))]), true, flags: JSON_THROW_ON_ERROR);
    jsonSave($out . '/image-source.json', $builtFiles);
    $report['image_vs_initial_source_mismatches'] = array_keys(array_diff_assoc($builtFiles, $initial['production_file_sha256']));
    foreach (['demo.jpg', 'face.jpg', 'blend.png'] as $file) {
        $original = dirname(__DIR__) . '/upload/' . $file;
        if (!is_file($original)) { throw new RuntimeException('Missing existing fixture ' . $original); }
        copy($original, $temporary . '/source/' . $file);
        $report['fixtures'][$file] = ['provenance' => 'existing upload/' . $file . '; upstream author/license not established; not redistributed',
            'sha256' => hash_file('sha256', $original), 'bytes' => filesize($original), 'metadata' => getimagesize($original)];
    }
    Image::newFromFile($temporary . '/source/demo.jpg')->resize(8)->jpegsave($temporary . '/source/large.jpg', ['Q' => 90]);
    $report['fixtures']['large.jpg'] = ['provenance' => 'local demo.jpg enlarged 8x via libvips resize default kernel, JPEG Q90; synthetic scale derivative',
        'sha256' => hash_file('sha256', $temporary . '/source/large.jpg'), 'bytes' => filesize($temporary . '/source/large.jpg'),
        'metadata' => getimagesize($temporary . '/source/large.jpg')];
    for ($round = 1; $round <= $rounds; ++$round) {
        $roundModes = $round % 2 === 0 ? array_reverse($modes) : $modes;
        foreach ($concurrencies as $concurrency) {
            foreach ($roundModes as $mode) {
                $name = 'eva-sustained-' . bin2hex(random_bytes(5));
                $args = ['docker', 'run', '-d', '--name', $name, '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
                    '--memory', '512m', '--cpus', '2', '--pids-limit', '256', '--tmpfs', '/tmp', '--tmpfs', '/config/caddy:uid=33,gid=33',
                    '--tmpfs', '/data/caddy:uid=33,gid=33', '--tmpfs', '/data/cache:uid=33,gid=33',
                    '--mount', 'type=bind,source=' . $temporary . '/source,target=/data/images,readonly',
                    '-e', 'EVATHUMBER_PHP_BINARY=/usr/local/bin/php', '-p', '127.0.0.1::8081'];
                if (isset($options['workers'])) { array_push($args, '-e', 'EVATHUMBER_POOL_SIZE=' . (int) $options['workers']); }
                if (isset($options['worker-max-jobs'])) { array_push($args, '-e', 'EVATHUMBER_WORKER_MAX_JOBS=' . (int) $options['worker-max-jobs']); }
                if ($mode === 'isolated') { array_push($args, '-e', 'EVATHUMBER_POOL_SOCKET=', '--entrypoint', '/usr/local/bin/frankenphp'); }
                if (($options['source-storage'] ?? 'bind') === 'tmpfs') {
                    $mountIndex = array_search('--mount', $args, true);
                    array_splice($args, $mountIndex, 2, ['--tmpfs', '/data/images:uid=33,gid=33']);
                }
                $args[] = $imageId;
                if ($mode === 'isolated') { array_push($args, 'run', '--config', '/etc/frankenphp/Caddyfile', '--adapter', 'caddyfile'); }
                command($args);
                if (($options['source-storage'] ?? 'bind') === 'tmpfs') {
                    foreach (glob($temporary . '/source/*') as $fixture) {
                        $copy = new Process(['docker', 'exec', '-i', $name, 'php', '-r', 'file_put_contents($argv[1], stream_get_contents(STDIN));',
                            '/data/images/' . basename($fixture)], input: file_get_contents($fixture), timeout: 30);
                        $copy->mustRun();
                    }
                }
                $base = 'http://' . command(['docker', 'port', $name, '8081']);
                $ready = false; $readinessDeadline = microtime(true) + 20;
                do {
                    $probe = new Process(['curl', '-fsS', '--max-time', '1', '--noproxy', '*', $base . '/healthz']);
                    $probe->run(); if ($probe->isSuccessful()) { $ready = true; break; } usleep(10000);
                } while (microtime(true) < $readinessDeadline);
                if (!$ready) { throw new RuntimeException('Container readiness failed'); }
                command(['curl', '-fsS', '--max-time', '20', '--noproxy', '*', '-o', '/dev/null', $base . workload('hot', 0, 0)[0]]);
                if (isset($options['curl-diagnostic'])) {
                    $diagnostic = [];
                    for ($i = 0; $i < 32; ++$i) {
                        $diagnostic[] = json_decode(command(['curl', '-sS', '--max-time', '20', '--noproxy', '*', '-o', '/dev/null',
                            '-w', '%{json}', $base . workload('different', $i, 90000000)[0]]), true, flags: JSON_THROW_ON_ERROR);
                    }
                    jsonSave($out . '/' . $mode . '-curl-diagnostic.json', $diagnostic);
                    $spanCode = <<<'PHP'
require '/app/vendor/autoload.php';
$rows=[];
for($i=0;$i<32;++$i){
    $t=hrtime(true); $source=(new \EvaThumber\Source\LocalSource('/data/images'))->resolve('large.jpg'); $resolved=hrtime(true);
    $snapshot=\EvaThumber\Source\SourceSnapshot::read('/data/images/large.jpg',33554432); $read=hrtime(true);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($snapshot->content); $sniff=hrtime(true);
    $rows[]=['resolve_ms'=>($resolved-$t)/1e6,'snapshot_ms'=>($read-$resolved)/1e6,'finfo_ms'=>($sniff-$read)/1e6];
}
echo json_encode($rows);
PHP;
                    jsonSave($out . '/' . $mode . '-source-spans.json', json_decode(command(['docker', 'exec', $name, 'php', '-r', $spanCode]), true, flags: JSON_THROW_ON_ERROR));
                }
                foreach ($scenarios as $scenarioIndex => $scenario) {
                    $label = $mode . '-r' . $round . '-c' . $concurrency . '-' . $scenario;
                    echo $label . " starting\n";
                    $eventsBefore = count(poolEvents($name));
                    $input = new InputStream();
                    $sampler = new Process(['docker', 'exec', '-i', $name, 'php', '-r', 'eval(base64_decode(trim(fgets(STDIN))));'], input: $input, timeout: 120);
                    $sampler->start(); $input->write(base64_encode(observerCode()) . "\n");
                    if (!$sampler->waitUntil(static fn (string $type, string $data): bool => str_contains($data, "READY\n"))) { throw new RuntimeException('Observer failed'); }
                    $bodyDir = $temporary . '/bodies'; mkdir($bodyDir);
                    $batch = measuredLoad($base, $scenario, $concurrency, $seconds, ($round * 10000000) + ($concurrency * 100000) + ($scenarioIndex * 10000), $bodyDir);
                    $input->write("STOP\n"); $input->close();
                    if ($sampler->wait() !== 0) { throw new RuntimeException('Observer failed: ' . $sampler->getErrorOutput()); }
                    $resources = json_decode(substr($sampler->getOutput(), strlen("READY\n")), true, flags: JSON_THROW_ON_ERROR); $sampler = null;
                    jsonSave($out . '/' . $label . '.resources.json', $resources);
                    $events = array_slice(poolEvents($name), $eventsBefore); jsonSave($out . '/' . $label . '.pool-events.json', $events);
                    $decode = [];
                    foreach ($batch['bodies'] as $hash => $spec) {
                        try {
                            $decoded = Image::newFromFile($bodyDir . '/' . $hash); $average = $decoded->avg();
                            $decode[$hash] = ['width' => $decoded->width, 'height' => $decoded->height, 'pixel_average' => $average, 'class' => $spec[1]];
                            if ($decoded->width !== $spec[2] || ($spec[3] !== null && $decoded->height !== $spec[3])) { $batch['failures'][] = 'Decoded dimension mismatch ' . $hash; }
                            unset($decoded);
                        } catch (Throwable $e) { $batch['failures'][] = 'Decode failed: ' . $e->getMessage(); }
                        unlink($bodyDir . '/' . $hash);
                    }
                    rmdir($bodyDir);
                    $load = array_values(array_filter($batch['rows'], static fn ($r) => $r['kind'] === 'load'));
                    $codes = []; $byStatus = []; $errors = []; $hits = 0; $successWindow = 0; $hashes = []; $classes = []; $latencyBins = [];
                    foreach ($load as $row) {
                        $codes[$row['status']] = ($codes[$row['status']] ?? 0) + 1; $byStatus[$row['status']][] = $row['ms'];
                        if ($row['error'] !== null) { $errors[$row['error']] = ($errors[$row['error']] ?? 0) + 1; }
                        $hits += $row['cache'] === 'HIT' ? 1 : 0;
                        $successWindow += $row['status'] === 200 && $row['finish_s'] <= $seconds ? 1 : 0;
                        if ($row['sha256'] !== null) { $hashes[$row['sha256']] = true; }
                        $classes[$row['class']][$row['status']][] = $row['ms'];
                        $bin = (string) (floor($row['ms'] / 25) * 25); $latencyBins[$bin] = ($latencyBins[$bin] ?? 0) + 1;
                    }
                    $probes = [];
                    foreach (['health', 'hit'] as $kind) {
                        $selected = array_values(array_filter($batch['rows'], static fn ($r) => $r['kind'] === $kind));
                        $probes[$kind] = percentiles(array_column($selected, 'ms')) + ['status_counts' => array_count_values(array_column($selected, 'status'))];
                    }
                    $jobStarts = count(array_filter($events, static fn ($e) => $e['event'] === 'job_started'));
                    $poolErrors = []; $jobDurations = []; $busy = []; $maxQueue = 0;
                    foreach ($events as $event) {
                        $maxQueue = max($maxQueue, $event['queued'] ?? 0);
                        if ($event['event'] === 'job_started') { $busy[$event['pid']] = $event['monotonic_ns']; }
                        if ($event['event'] === 'job_finished' && isset($busy[$event['pid']])) { $jobDurations[] = ($event['monotonic_ns'] - $busy[$event['pid']]) / 1e6; unset($busy[$event['pid']]); }
                        if ($event['event'] === 'pool_response' && ($event['error'] ?? null) !== null) { $poolErrors[$event['error']] = ($poolErrors[$event['error']] ?? 0) + 1; }
                    }
                    if ($scenario === 'same' && (count($hashes) !== 1 || ($mode === 'pool' && $jobStarts !== 1))) { $batch['failures'][] = 'Same-key invariant failed: hashes=' . count($hashes) . ' jobs=' . $jobStarts; }
                    foreach ($classes as &$statuses) { foreach ($statuses as &$values) { $values = percentiles($values); } unset($values); } unset($statuses);
                    $summary = ['label' => $label, 'mode' => $mode, 'round' => $round, 'concurrency' => $concurrency, 'scenario' => $scenario,
                        'window_seconds' => $seconds, 'elapsed_seconds' => $batch['elapsed_seconds'], 'requests' => count($load), 'codes' => $codes,
                        'success_rps' => $successWindow / $seconds, 'drained_success_rps' => ($codes[200] ?? 0) / $batch['elapsed_seconds'],
                        'latency_by_status' => array_map('percentiles', $byStatus), 'class_latency_by_status' => $classes, 'latency_histogram_25ms' => $latencyBins,
                        'errors' => $errors, 'pool_rejection_errors' => $poolErrors, 'pre_pool_or_http_rejections' => array_sum($errors) - array_sum($poolErrors),
                        'hit_ratio' => $hits / max(1, count($load)), 'probes' => $probes, 'pool_jobs_started' => $mode === 'pool' ? $jobStarts : null,
                        'pool_job_latency' => percentiles($jobDurations), 'max_pool_queue' => $maxQueue, 'same_key_unique_hashes' => $scenario === 'same' ? count($hashes) : null,
                        'unique_bodies_fully_decoded' => count($decode), 'decode_evidence' => $decode,
                        'cpu_seconds' => $resources['cpu_seconds'], 'cpu_cores_average' => $resources['cpu_seconds'] / $resources['observer_seconds'],
                        'cgroup_memory_peak_bytes' => $resources['cgroup_memory_peak_bytes'], 'process_rss_peak_bytes' => $resources['process_rss_peak_bytes'],
                        'failures' => array_values(array_unique($batch['failures']))];
                    // Compressed newline-delimited JSON preserves every completion without bloating tracked evidence.
                    $raw = gzopen($out . '/' . $label . '.requests.jsonl.gz', 'wb9');
                    foreach ($batch['rows'] as $row) { gzwrite($raw, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"); } gzclose($raw);
                    $report['runs'][] = $summary;
                    foreach ($summary['failures'] as $failure) { $report['failures'][] = $label . ': ' . $failure; }
                    jsonSave($out . '/report.json', $report);
                    echo json_encode(['label' => $label, 'success_rps' => $summary['success_rps'], 'codes' => $codes, 'latency' => $summary['latency_by_status'],
                        'cpu_cores' => $summary['cpu_cores_average'], 'cgroup_mib' => $summary['cgroup_memory_peak_bytes'] / 1048576,
                        'jobs' => $summary['pool_jobs_started'], 'job_latency' => $summary['pool_job_latency'], 'failures' => $summary['failures']], JSON_UNESCAPED_SLASHES) . "\n";
                    unset($batch, $load, $byStatus, $events, $resources);
                    gc_collect_cycles();
                }
                command(['docker', 'stop', '--timeout', '45', $name], 60);
                $state = json_decode(command(['docker', 'inspect', '--format', '{{json .State}}', $name]), true, flags: JSON_THROW_ON_ERROR);
                $log = new Process(['docker', 'logs', $name]); $log->mustRun();
                file_put_contents($out . '/' . $mode . '-r' . $round . '-c' . $concurrency . '.log.gz', gzencode($log->getOutput() . $log->getErrorOutput(), 9));
                $report['container_exits'][] = ['mode' => $mode, 'round' => $round, 'concurrency' => $concurrency, 'exit_code' => $state['ExitCode'], 'oom_killed' => $state['OOMKilled']];
                if ($state['ExitCode'] !== 0 || $state['OOMKilled']) { $report['failures'][] = $name . ': unclean shutdown'; }
                command(['docker', 'rm', $name]); $name = null;
            }
        }
    }
} catch (Throwable $e) {
    $report['failures'][] = get_class($e) . ': ' . $e->getMessage(); fwrite(STDERR, $e->getMessage() . "\n");
} finally {
    if ($sampler !== null && $sampler->isRunning()) { $sampler->stop(0); }
    if ($name !== null) {
        $log = new Process(['docker', 'logs', $name]); $log->run(); file_put_contents($out . '/failure-container.log', $log->getOutput() . $log->getErrorOutput());
        $remove = new Process(['docker', 'rm', '-f', $name]); $remove->run();
    }
    $final = snapshot(); jsonSave($out . '/final-source.json', $final);
    $report['final_source'] = $final['production_tree_sha256']; $report['source_changed_during_run'] = $report['initial_source'] !== $report['final_source'];
    $report['finished_utc'] = gmdate(DATE_ATOM); jsonSave($out . '/report.json', $report);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    } rmdir($temporary);
}
echo 'Evidence: ' . $out . "\n";
exit($report['failures'] === [] ? 0 : 1);
