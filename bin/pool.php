<?php

declare(strict_types=1);

// Single local supervisor. Workers cannot write cache destinations: only private
// staging files are sent to workers; the supervisor copies after confirmed success.
require dirname(__DIR__) . '/vendor/autoload.php';

use EvaThumber\Http\Settings;

$settings = Settings::fromEnvironment();
if ($settings->poolSocket === '' || !extension_loaded('pcntl')) {
    throw new RuntimeException('Pool requires POOL_SOCKET and pcntl.');
}
umask(0077);
$directory = dirname($settings->poolSocket);
if (!is_dir($directory) && !mkdir($directory, 0700, true)) { throw new RuntimeException('Cannot create pool directory'); }
$owner = fopen($directory . '/supervisor.lock', 'c');
if ($owner === false || !flock($owner, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Pool already running'); }
// A SIGKILL cannot run finally. The exclusive owner removes abandoned stages
// before any new worker can touch them (including slots from an older pool size).
foreach (glob($directory . '/stage-*') ?: [] as $stage) {
    if (preg_match('/^stage-[0-9]+$/D', basename($stage)) && !is_dir($stage)) { unlink($stage); }
}
if (file_exists($settings->poolSocket)) { unlink($settings->poolSocket); }
$server = stream_socket_server('unix://' . $settings->poolSocket, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['socket' => ['backlog' => 16]]));
if ($server === false) { throw new RuntimeException($error ?? 'Cannot listen on pool socket'); }
stream_set_blocking($server, false);
$stopping = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$stopping): void { $stopping = true; });
pcntl_signal(SIGINT, static function () use (&$stopping): void { $stopping = true; });
$workers = [];
$clients = [];
$queue = [];
$emit = static function (array $event): void { fwrite(STDERR, json_encode($event + ['monotonic_ns' => hrtime(true)], JSON_THROW_ON_ERROR) . "\n"); };
$responseSequence = 0;
$respond = static function ($client, array $reply) use (&$workers, &$queue, &$responseSequence, $settings, $emit): void {
    $active = count(array_filter($workers, static fn (array $worker): bool => $worker['client'] !== null));
    $emit(['event' => 'pool_response', 'sequence' => ++$responseSequence, 'status' => $reply['status'],
        'error' => $reply['error'] ?? null, 'active' => $active, 'capacity' => $settings->poolSize, 'queued' => count($queue)]);
    @fwrite($client, json_encode(['protocol' => 1] + $reply, JSON_THROW_ON_ERROR) . "\n");
    fclose($client);
};
$spawn = static function (int $slot) use ($directory, $settings, $emit): ?array {
    $pipes = [];
    $process = @proc_open([$settings->phpBinary, '-d', 'ffi.enable=true', __DIR__ . '/process-guard.php',
        (string) getmypid(), __DIR__ . '/pool-worker.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    if ($process === false) { return null; }
    stream_set_blocking($pipes[0], false);
    stream_set_blocking($pipes[1], false);
    $pid = proc_get_status($process)['pid'];
    $emit(['event' => 'worker_start', 'slot' => $slot, 'pid' => $pid]);
    return ['process' => $process, 'in' => $pipes[0], 'out' => $pipes[1], 'pid' => $pid,
        'ready' => false, 'buffer' => '', 'writeBuffer' => '', 'client' => null, 'destination' => null,
        'stage' => $directory . '/stage-' . $slot, 'deadline' => hrtime(true) / 1e9 + 10,
        'started' => hrtime(true) / 1e9, 'jobs' => 0];
};
// Keep failure history outside the process record. A readiness frame alone must
// not reset a rapid ready/crash loop. 30s of service resets the history; planned
// retirement avoids delay. Failed slots never block queue expiry/shutdown.
$restarts = array_fill(0, $settings->poolSize, ['failures' => 0, 'at' => 0.0]);
$backoff = static function (int $slot, string $reason) use (&$restarts, $emit): void {
    $failures = min(7, $restarts[$slot]['failures'] + 1);
    $delay = min(5.0, 0.1 * (2 ** ($failures - 1)));
    $restarts[$slot] = ['failures' => $failures, 'at' => hrtime(true) / 1e9 + $delay];
    $emit(['event' => 'worker_backoff', 'slot' => $slot, 'reason' => $reason,
        'failures' => $failures, 'delay_ms' => (int) round($delay * 1000)]);
};
$copyStage = static function (array $worker): bool {
    // Never recreate a destination after its HTTP owner has abandoned it.
    clearstatcache(true, $worker['destination']);
    if (is_link($worker['destination'])) { return false; }
    $destination = @fopen($worker['destination'], 'r+b');
    if ($destination === false) { return false; }
    $source = @fopen($worker['stage'], 'rb');
    try {
        $stat = fstat($destination);
        clearstatcache(true, $worker['destination']);
        $current = @stat($worker['destination']);
        if ($source === false || $stat === false || $current === false
            || [$stat['dev'], $stat['ino']] !== $worker['destinationIdentity']
            || [$current['dev'], $current['ino']] !== $worker['destinationIdentity']) { return false; }
        return stream_copy_to_stream($source, $destination) !== false && fflush($destination);
    } finally {
        if (is_resource($source)) { fclose($source); }
        fclose($destination);
    }
};
$retire = static function (array &$worker, string $reason) use ($emit): void {
    proc_terminate($worker['process'], SIGKILL);
    fclose($worker['in']);
    fclose($worker['out']);
    proc_close($worker['process']); // reap before making the slot reusable
    if (is_file($worker['stage'])) { unlink($worker['stage']); }
    $emit(['event' => 'worker_stop', 'pid' => $worker['pid'], 'reason' => $reason, 'jobs' => $worker['jobs']]);
};
try {
    while (true) {
        $now = hrtime(true) / 1e9;
        if (!$stopping) {
            foreach ($restarts as $slot => $restart) {
                if (isset($workers[$slot]) || $now < $restart['at']) { continue; }
                $spawned = $spawn($slot);
                if ($spawned === null) { $backoff($slot, 'spawn_failed'); }
                else { $workers[$slot] = $spawned; }
            }
        }
        foreach ($workers as $slot => &$worker) {
            $reason = null;
            if ($worker['writeBuffer'] !== '') {
                $written = @fwrite($worker['in'], $worker['writeBuffer']);
                if ($written === false) { $reason = 'worker_exit'; }
                else { $worker['writeBuffer'] = substr($worker['writeBuffer'], $written); }
            }
            $chunk = stream_get_contents($worker['out'], 4097 - strlen($worker['buffer']));
            if ($chunk !== false) { $worker['buffer'] .= $chunk; }
            if ((!$worker['ready'] || $worker['client'] !== null) && hrtime(true) / 1e9 >= $worker['deadline']) {
                $reason = 'processing_timeout';
            }
            if (strlen($worker['buffer']) > 4096) { $reason = 'protocol_error'; }
            elseif ($reason === null && str_contains($worker['buffer'], "\n")) {
                $reply = json_decode(trim($worker['buffer']), true);
                $worker['buffer'] = '';
                if (!$worker['ready'] && is_array($reply) && ($reply['protocol'] ?? null) === 1 && ($reply['ready'] ?? false) === true) {
                    $worker['ready'] = true;
                    $emit(['event' => 'worker_ready', 'pid' => $worker['pid']]);
                } elseif ($worker['client'] !== null && is_array($reply) && ($reply['protocol'] ?? null) === 1
                    && is_int($reply['status'] ?? null) && ($reply['status'] === 200 || ($reply['status'] >= 400 && $reply['status'] <= 599))) {
                    if ($reply['status'] === 200 && !$copyStage($worker)) {
                        $reply = ['status' => 503, 'error' => 'cache_unavailable'];
                    }
                    if (is_file($worker['stage'])) { unlink($worker['stage']); }
                    $respond($worker['client'], $reply);
                    $worker['client'] = null;
                    ++$worker['jobs'];
                    $emit(['event' => 'job_finished', 'pid' => $worker['pid'], 'status' => $reply['status'], 'jobs' => $worker['jobs']]);
                    if ($worker['jobs'] >= $settings->workerMaxJobs) { $reason = 'max_jobs'; }
                } else { $reason = 'protocol_error'; }
            }
            $state = proc_get_status($worker['process']);
            if (!$state['running']) { $reason = 'worker_exit'; }
            if ((!$worker['ready'] || $worker['client'] !== null) && $now >= $worker['deadline']) { $reason = 'processing_timeout'; }
            if ($worker['client'] !== null && feof($worker['client'])) { $reason = 'client_disconnected'; }
            // Linux actual RSS includes native allocations, unlike PHP memory_limit.
            // A worker never gives memory back between jobs, so a long-lived one drifts
            // up to the high-water mark of the largest source it has served. Recycling
            // on that alone turns healthy work into 503: a progressive JPEG of 17.9 MPix
            // needs 167 MiB for one 600px resize, and q_auto builds the graph twice for
            // 178 MiB, against this 192 MiB cap. So over budget retires an *idle*
            // worker, before it is handed the next job, and a job already running is
            // left to finish and publish its cache entry. See docs/components/Image.
            $procStatus = @file_get_contents('/proc/' . $worker['pid'] . '/status');
            if ($procStatus !== false && preg_match('/VmRSS:\s+(\d+)/', $procStatus, $match)
                && (int) $match[1] > $settings->workerRssMiB * 1024 && $worker['client'] === null) {
                $reason = 'worker_memory_limit';
            }
            if ($reason !== null) {
                $client = $worker['client'];
                $retire($worker, $reason);
                if ($client !== null) { $respond($client, ['status' => $reason === 'processing_timeout' ? 504 : 503, 'error' => $reason]); }
                $history = $restarts[$slot];
                $restarts[$slot] = ['failures' => $now - $worker['started'] >= 30 ? 0 : $history['failures'], 'at' => 0.0];
                // Budget and lifetime retirements are planned and follow successful
                // work, so they must not count as failures: escalating them delays
                // every later job by up to 5s while a fresh worker would serve the
                // same job several times over before drifting back over the cap.
                if (!$stopping && !in_array($reason, ['max_jobs', 'client_disconnected', 'worker_memory_limit'], true)) {
                    $backoff($slot, $reason);
                }
                unset($workers[$slot]);
            } elseif ($stopping && $worker['client'] === null) {
                // Idle workers hold no task. Kill/reap avoids inherited pipe
                // descriptors delaying EOF when sibling workers are still alive.
                $retire($worker, 'shutdown_idle');
                unset($workers[$slot]);
            }
        }
        unset($worker);
        if ($stopping) {
            foreach ($clients as $client) { $respond($client['stream'], ['status' => 503, 'error' => 'pool_stopping']); }
            foreach ($queue as $pending) { $respond($pending['stream'], ['status' => 503, 'error' => 'pool_stopping']); }
            $clients = [];
            $queue = [];
            // Connections the kernel completed before SIGTERM but the loop had
            // not accepted yet must also be answered, never silently dropped.
            while (($stream = @stream_socket_accept($server, 0)) !== false) {
                $respond($stream, ['status' => 503, 'error' => 'pool_stopping']);
            }
            if ($workers === []) { break; }
        } else {
            // At most 16 short-lived incomplete frames, separate from the FIFO.
            // Expire queued work before accepting more, without extending deadlines.
            foreach ($queue as $id => $pending) {
                if (hrtime(true) >= $pending['deadline'] || feof($pending['stream'])) {
                    $respond($pending['stream'], ['status' => 503, 'error' => 'queue_timeout']);
                    unset($queue[$id]);
                }
            }
            for ($accepted = 0; $accepted < 32 && ($stream = @stream_socket_accept($server, 0)) !== false; ++$accepted) {
                stream_set_blocking($stream, false);
                if (count($clients) >= 16) { $respond($stream, ['status' => 503, 'error' => 'processor_busy']); continue; }
                $clients[(int) $stream] = ['stream' => $stream, 'buffer' => '', 'deadline' => $now + 0.2];
            }
            foreach ($clients as $id => &$client) {
                $chunk = stream_get_contents($client['stream'], 16385 - strlen($client['buffer']));
                if ($chunk !== false) { $client['buffer'] .= $chunk; }
                if (strlen($client['buffer']) > 16384 || $now > $client['deadline'] || feof($client['stream'])) {
                    $respond($client['stream'], ['status' => 503, 'error' => 'invalid_job']); unset($clients[$id]); continue;
                }
                if (!str_contains($client['buffer'], "\n")) { continue; }
                $job = json_decode(trim($client['buffer']), true, 16);
                $valid = is_array($job) && ($job['protocol'] ?? null) === 1;
                if ($valid && ($job['op'] ?? null) === 'status') {
                    // Readiness probe: one cheap local frame, never a job, never an
                    // event, so probes cannot be mistaken for served work.
                    $idle = 0;
                    $busy = 0;
                    foreach ($workers as $probe) {
                        if (!$probe['ready']) { continue; }
                        if ($probe['client'] === null) { ++$idle; } else { ++$busy; }
                    }
                    @fwrite($client['stream'], json_encode(['protocol' => 1, 'status' => 200, 'idle' => $idle,
                        'active' => $busy, 'queued' => count($queue), 'capacity' => $settings->poolSize], JSON_THROW_ON_ERROR) . "\n");
                    fclose($client['stream']);
                    unset($clients[$id]);
                    continue;
                }
                foreach (['publicId', 'transformation', 'destination', 'format', 'identity'] as $field) { $valid = $valid && is_string($job[$field] ?? null); }
                $cache = realpath($settings->cache);
                $valid = $valid && $cache !== false && realpath(dirname($job['destination'])) === $cache
                    && str_starts_with(basename($job['destination']), '.tmp-') && !is_link($job['destination']) && is_file($job['destination']);
                if (!$valid) { $respond($client['stream'], ['status' => 400, 'error' => 'invalid_job']); unset($clients[$id]); continue; }
                $destinationStat = @stat($job['destination']);
                if ($destinationStat === false) { $respond($client['stream'], ['status' => 503, 'error' => 'cache_unavailable']); unset($clients[$id]); continue; }
                $destinationIdentity = [$destinationStat['dev'], $destinationStat['ino']];
                // Dispatch below; count idle workers as immediately available slots.
                $idle = count(array_filter($workers, static fn (array $worker): bool => $worker['ready'] && $worker['client'] === null));
                if (count($queue) >= $settings->poolQueueSize + $idle) {
                    $respond($client['stream'], ['status' => 503, 'error' => 'processor_busy']);
                } else {
                    $queue[] = ['stream' => $client['stream'], 'job' => $job, 'destinationIdentity' => $destinationIdentity,
                        'deadline' => hrtime(true) + $settings->poolQueueMilliseconds * 1_000_000];
                    $emit(['event' => 'job_queued', 'queued' => count($queue)]);
                }
                unset($clients[$id]);
            }
            unset($client);
            foreach ($workers as &$worker) {
                if (!$worker['ready'] || $worker['client'] !== null || $queue === []) { continue; }
                $pending = array_shift($queue);
                if (hrtime(true) >= $pending['deadline'] || feof($pending['stream'])) {
                    $respond($pending['stream'], ['status' => 503, 'error' => 'queue_timeout']);
                    continue;
                }
                $job = $pending['job'];
                $worker['client'] = $pending['stream'];
                $worker['destination'] = $job['destination'];
                $worker['destinationIdentity'] = $pending['destinationIdentity'];
                $worker['deadline'] = hrtime(true) / 1e9 + $settings->timeout;
                unset($job['destination']);
                $job['stage'] = $worker['stage'];
                $worker['writeBuffer'] = json_encode($job, JSON_THROW_ON_ERROR) . "\n";
                $emit(['event' => 'job_started', 'pid' => $worker['pid'], 'queued' => count($queue)]);
            }
            unset($worker);
        }
        // Do not spin on an unread listening socket while draining active work.
        $read = $stopping ? [] : [$server];
        foreach ($workers as $worker) { $read[] = $worker['out']; }
        $write = $except = [];
        if ($read !== []) { @stream_select($read, $write, $except, 0, 5000); }
    }
} finally {
    foreach ($clients as $client) { fclose($client['stream']); }
    foreach ($queue as $pending) { fclose($pending['stream']); }
    foreach ($workers as &$worker) {
        $retire($worker, 'supervisor_exit');
        if ($worker['client'] !== null) { fclose($worker['client']); }
    }
    fclose($server);
    unlink($settings->poolSocket);
    fclose($owner);
}
