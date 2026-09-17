<?php

declare(strict_types=1);

// PID 1 owns both process trees. Stop HTTP admission first; retain the transform
// pool until HTTP has drained, then stop/reap the pool. Never spawn per request.
require dirname(__DIR__) . '/vendor/autoload.php';
$stopping = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$stopping): void { $stopping = true; });
pcntl_signal(SIGINT, static function () use (&$stopping): void { $stopping = true; });
$children = [];
$start = static function (array $command) {
    $process = proc_open(array_values($command), [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if ($process === false) { throw new RuntimeException('Cannot start service'); }
    return $process;
};
$exit = 0;
try {
    $guard = [PHP_BINARY, '-d', 'ffi.enable=true', __DIR__ . '/process-guard.php', (string) getmypid()];
    $children['pool'] = $start([...$guard, __DIR__ . '/pool.php']);
    $children['http'] = $start([...$guard, '--exec', '/usr/local/bin/frankenphp', 'run', '--config', '/etc/frankenphp/Caddyfile', '--adapter', 'caddyfile']);
    while (!$stopping) {
        foreach ($children as $child) {
            if (!proc_get_status($child)['running']) { $exit = 1; $stopping = true; break; }
        }
        usleep(20_000);
    }
} finally {
    foreach (['http', 'pool'] as $name) {
        if (!isset($children[$name])) { continue; }
        $child = $children[$name];
        proc_terminate($child, SIGTERM);
        $deadline = hrtime(true) / 1e9 + 20;
        while (proc_get_status($child)['running'] && hrtime(true) / 1e9 < $deadline) { usleep(20_000); }
        if (proc_get_status($child)['running']) { proc_terminate($child, SIGKILL); $exit = 1; }
        proc_close($child);
    }
}
exit($exit);
