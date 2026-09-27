<?php

declare(strict_types=1);

// Bounded local production diagnostic. No benchmark-driver or pool lifecycle changes.
// php tests/cache-http-timing.php IMAGE /absolute/evidence-directory [concurrency] [max-publication-p95-ms]
require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Process\Process;

$image = $argv[1] ?? 'evathumber:admission-arm64';
$out = $argv[2] ?? throw new InvalidArgumentException('Evidence directory required.');
$concurrency = (int) ($argv[3] ?? 1);
$maxPublicationP95 = isset($argv[4]) ? filter_var($argv[4], FILTER_VALIDATE_FLOAT) : null;
if ($concurrency < 1 || $concurrency > 8 || file_exists($out)
    || ($maxPublicationP95 !== null && ($maxPublicationP95 === false || $maxPublicationP95 <= 0))) {
    throw new InvalidArgumentException('Use concurrency 1..8, a new evidence directory and an optional positive publication p95 limit.');
}
mkdir($out, 0770, true);
$run = static function (array $args): string {
    $p = new Process($args, timeout: 120);
    $p->mustRun();
    return trim($p->getOutput());
};
$name = 'eva-cache-timing-' . bin2hex(random_bytes(5));
$created = false;
$multi = curl_multi_init();
try {
    $imageId = $run(['docker', 'image', 'inspect', '--format', '{{.Id}}', $image]);
    file_put_contents($out . '/image.txt', $imageId . "\n");
    $args = ['docker', 'run', '-d', '--name', $name, '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
        '--memory', '512m', '--cpus', '2', '--pids-limit', '256', '--tmpfs', '/tmp', '--tmpfs', '/config/caddy:uid=33,gid=33',
        '--tmpfs', '/data/caddy:uid=33,gid=33', '--tmpfs', '/data/cache:uid=33,gid=33', '--tmpfs', '/data/images:uid=33,gid=33',
        '-e', 'EVATHUMBER_SERVER_TIMING=1', '-e', 'EVATHUMBER_PHP_BINARY=/usr/local/bin/php', '-p', '127.0.0.1::8080'];
    $trace = getenv('EVATHUMBER_TRACE_BINARY');
    if ($trace !== false) {
        array_push($args, '--mount', 'type=bind,source=' . $trace . ',target=/usr/local/bin/eva-strace,readonly',
            '--entrypoint', '/usr/local/bin/eva-strace', $imageId, '-f', '-ttt', '-T', '-o', '/tmp/syscalls.log',
            '-e', 'trace=rename,renameat,renameat2,flock,futex,clock_nanosleep', 'php', '/app/bin/serve.php');
    } else {
        $args[] = $imageId;
    }
    $run($args);
    $created = true;
    $copy = new Process(['docker', 'exec', '-i', $name, 'php', '-r', 'file_put_contents("/data/images/demo.jpg", stream_get_contents(STDIN));'],
        input: file_get_contents(dirname(__DIR__) . '/upload/demo.jpg'), timeout: 30);
    $copy->mustRun();
    $run(['docker', 'exec', $name, 'php', '-r', 'require "/app/vendor/autoload.php"; \Jcupitt\Vips\Image::newFromFile("/data/images/demo.jpg")->resize(8)->jpegsave("/data/images/large.jpg", ["Q"=>90]);']);
    $base = 'http://' . $run(['docker', 'port', $name, '8080']);
    $run(['curl', '-fsS', '--retry', '10', '--retry-connrefused', '--retry-delay', '0', '--max-time', '20', '--noproxy', '*', $base . '/healthz']);
    $start = hrtime(true);
    $deadline = $start + 10_000_000_000;
    $active = []; $rows = []; $sequence = 0;
    $launch = static function () use ($multi, $base, &$active, &$sequence): void {
        $url = '/image/upload/c_fill,w_900,h_700/f_webp,q_80/v' . (80000000 + $sequence++) . '/large.jpg';
        $ch = curl_init($base . $url);
        $state = (object) ['url' => $url, 'headers' => []];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 20000, CURLOPT_PROXY => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use ($state): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $state->headers[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            }]);
        $active[spl_object_id($ch)] = $state;
        curl_multi_add_handle($multi, $ch);
    };
    for ($i = 0; $i < $concurrency; ++$i) { $launch(); }
    do {
        do { $result = curl_multi_exec($multi, $running); } while ($result === CURLM_CALL_MULTI_PERFORM);
        if ($result !== CURLM_OK) { throw new RuntimeException('curl multi failed.'); }
        while (($done = curl_multi_info_read($multi)) !== false) {
            $ch = $done['handle']; $state = $active[spl_object_id($ch)];
            $info = curl_getinfo($ch); $body = (string) curl_multi_getcontent($ch);
            if ($done['result'] !== CURLE_OK) { throw new RuntimeException(curl_error($ch)); }
            $timings = [];
            preg_match_all('/([a-z_]+);dur=([0-9.]+)/', $state->headers['server-timing'] ?? '', $matches, PREG_SET_ORDER);
            foreach ($matches as $match) { $timings[$match[1]] = (float) $match[2]; }
            if (!isset($timings['app'])) { throw new RuntimeException('Missing server timing.'); }
            $rows[] = ['url' => $state->url, 'status' => $info['http_code'], 'ms' => $info['total_time'] * 1000,
                'ttfb_ms' => $info['starttransfer_time'] * 1000, 'timing' => $timings,
                'error' => $info['http_code'] === 200 ? null : json_decode($body, true), 'bytes' => strlen($body)];
            curl_multi_remove_handle($multi, $ch); unset($active[spl_object_id($ch)]);
            if (hrtime(true) < $deadline) { $launch(); }
        }
        if ($active !== []) { curl_multi_select($multi, .01); }
    } while ($active !== []);
    file_put_contents($out . '/requests.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $summary = ['concurrency' => $concurrency, 'requests' => count($rows), 'status' => array_count_values(array_column($rows, 'status')),
        'errors' => array_count_values(array_map(static fn (array $row): string => $row['error']['error'] ?? 'unknown',
            array_filter($rows, static fn (array $row): bool => $row['status'] !== 200)))];
    foreach (array_unique(array_merge(...array_map(static fn (array $row): array => array_keys($row['timing']), $rows))) as $stage) {
        $values = array_values(array_map(static fn (array $row): float => $row['timing'][$stage],
            array_filter($rows, static fn (array $row): bool => isset($row['timing'][$stage])))); sort($values);
        $summary['stages'][$stage] = ['p50' => $values[(int) ceil(count($values) * .5) - 1], 'p95' => $values[(int) ceil(count($values) * .95) - 1], 'max' => max($values)];
    }
    $values = array_column($rows, 'ms'); sort($values);
    $summary['http_ms'] = ['p50' => $values[(int) ceil(count($values) * .5) - 1], 'p95' => $values[(int) ceil(count($values) * .95) - 1]];
    file_put_contents($out . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    if ($maxPublicationP95 !== null) {
        $publications = array_values(array_map(static fn (array $row): float => $row['timing']['cache_publish'] + ($row['timing']['cache_unlink'] ?? 0),
            array_filter($rows, static fn (array $row): bool => isset($row['timing']['cache_publish']))));
        sort($publications);
        if (count($publications) < 20 || count($summary['status']) !== 1 || !isset($summary['status'][200])
            || $publications[(int) ceil(count($publications) * .95) - 1] > $maxPublicationP95) {
            throw new RuntimeException('Publication regression: require >=20 publications, all HTTP 200 and publication p95 within requested limit.');
        }
    }
} finally {
    curl_multi_close($multi);
    if ($created) {
        try {
            $logs = new Process(['docker', 'logs', $name]); $logs->run();
            file_put_contents($out . '/container.log', $logs->getOutput() . $logs->getErrorOutput());
            // docker cp does not expose this runtime tmpfs on all Docker backends.
            if ($trace !== false) {
                file_put_contents($out . '/syscalls.log', $run(['docker', 'exec', $name, 'cat', '/tmp/syscalls.log']));
            }
        } finally {
            $run(['docker', 'rm', '-f', $name]);
        }
    }
}
