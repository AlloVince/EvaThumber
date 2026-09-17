<?php

declare(strict_types=1);

// Two independent real HTTP servers share the production Kernel and cache.
// This isolates application serialization from HTTP server queue configuration.
require dirname(__DIR__) . '/vendor/autoload.php';

use Jcupitt\Vips\Image;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/eva-http-parallel-' . bin2hex(random_bytes(6));
mkdir($directory);
mkdir($directory . '/source');
mkdir($directory . '/cache');
$servers = [];
$clients = [];
try {
    $xy = Image::xyz(1800, 1400);
    $x = $xy->extract_band(0);
    $y = $xy->extract_band(1);
    $x->multiply(0.17)->sin()->add($y->multiply(0.13)->cos())
        ->multiply(60)->add(128)->bandjoin([$x->remainder(256), $y->remainder(256)])
        ->cast('uchar')->copy(['interpretation' => 'srgb'])->jpegsave($directory . '/source/foo.jpg');
    $ports = [];
    for ($i = 0; $i < 2; ++$i) {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) { throw new RuntimeException('Cannot reserve port'); }
        $address = stream_socket_get_name($socket, false);
        if ($address === false) { throw new RuntimeException('Cannot find port'); }
        $port = (int) substr(strrchr($address, ':'), 1);
        fclose($socket);
        $ports[] = $port;
        $server = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-S', '127.0.0.1:' . $port, 'public/index.php'], $root, [
            'EVATHUMBER_SOURCE' => $directory . '/source',
            'EVATHUMBER_CACHE' => $directory . '/cache',
            'EVATHUMBER_TIMEOUT' => '30',
        ], timeout: null);
        $server->start();
        $servers[] = $server;
        $deadline = microtime(true) + 5;
        do {
            $probe = @file_get_contents('http://127.0.0.1:' . $port . '/healthz', false, stream_context_create(['http' => ['timeout' => 0.2]]));
            if ($probe !== false) { break; }
            if (!$server->isRunning() || microtime(true) >= $deadline) {
                throw new RuntimeException('HTTP readiness failed: ' . $server->getErrorOutput());
            }
            usleep(10_000);
        } while (true);
    }
    $client = static function (int $port, int $version, string $output) use ($directory): Process {
        return new Process(['curl', '--silent', '--show-error', '--max-time', '35', '--output', $directory . '/' . $output,
            '--write-out', '%{http_code}', 'http://127.0.0.1:' . $port . '/image/upload/c_fill,w_1400,h_1100/f_avif,q_70/v' . $version . '/foo.jpg'], timeout: 40);
    };
    $durations = [];
    for ($i = 1; $i <= 3; ++$i) {
        $single = $client($ports[0], $i, 'single-' . $i);
        $clients[] = $single;
        $start = hrtime(true);
        $single->mustRun();
        $durations[] = (hrtime(true) - $start) / 1e9;
        if ($single->getOutput() !== '200') { throw new RuntimeException('Baseline HTTP ' . $single->getOutput()); }
    }
    sort($durations);
    $baseline = $durations[1];
    $first = $client($ports[0], 100, 'first');
    $second = $client($ports[1], 101, 'second');
    $clients[] = $first;
    $clients[] = $second;
    $start = hrtime(true);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();
    $elapsed = (hrtime(true) - $start) / 1e9;
    $codes = [$first->getOutput(), $second->getOutput()];
    echo json_encode(['baseline_seconds' => $baseline, 'single_samples' => $durations, 'parallel_seconds' => $elapsed,
        'ratio' => $elapsed / $baseline, 'http_codes' => $codes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    if ($codes !== ['200', '200']) { throw new RuntimeException('Both concurrent requests must succeed'); }
    foreach (['first', 'second'] as $output) {
        $image = Image::newFromFile($directory . '/' . $output);
        if ($image->width !== 1400 || $image->height !== 1100) { throw new RuntimeException('Invalid output dimensions'); }
    }
    if (hash_file('sha256', $directory . '/first') !== hash_file('sha256', $directory . '/second')) {
        throw new RuntimeException('Equivalent transforms returned different bytes');
    }
    if ($elapsed >= 1.8 * $baseline) { throw new RuntimeException('Different keys are not sufficiently parallel'); }
    echo "PASS: different cold keys succeed concurrently over HTTP\n";
} finally {
    foreach ($clients as $clientProcess) { if ($clientProcess->isRunning()) { $clientProcess->stop(0); } }
    foreach ($servers as $server) { $server->stop(0); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($directory);
}
