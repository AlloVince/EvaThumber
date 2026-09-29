<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Source\LocalSource;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PoolRecoveryTest extends TestCase
{
    private string $root;
    private ?Process $pool = null;
    private array $sockets = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) { self::markTestSkipped('Requires pcntl/posix'); }
        $this->root = '/tmp/er-' . bin2hex(random_bytes(5));
        mkdir($this->root); mkdir($this->root . '/cache');
        Image::black(40, 30, ['bands' => 3])->jpegsave($this->root . '/foo.jpg');
    }

    private function start(array $env = []): void
    {
        $this->pool = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/pool.php'], env: $env + [
            'EVATHUMBER_SOURCE' => $this->root, 'EVATHUMBER_CACHE' => $this->root . '/cache',
            'EVATHUMBER_POOL_SOCKET' => $this->root . '/pool.sock', 'EVATHUMBER_POOL_SIZE' => '1',
            'EVATHUMBER_TIMEOUT' => '2', 'EVATHUMBER_WORKER_RSS_MIB' => '192',
            'EVATHUMBER_POOL_QUEUE_MS' => '400', 'EVATHUMBER_PHP_BINARY' => PHP_BINARY,
        ], timeout: 40);
        $this->pool->start();
    }

    private function events(string $name): array
    {
        $events = [];
        foreach (explode("\n", $this->pool->getErrorOutput()) as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && ($event['event'] ?? null) === $name) { $events[] = $event; }
        }
        return $events;
    }

    private function await(string $name, int $count): array
    {
        $deadline = hrtime(true) + 8_000_000_000;
        do {
            $events = $this->events($name);
            if (count($events) >= $count) { return $events[$count - 1]; }
            usleep(5000);
        } while (hrtime(true) < $deadline && $this->pool->isRunning());
        self::fail('Missing ' . $name . ': ' . $this->pool->getErrorOutput());
    }

    private function send(string $publicId = 'foo', string $transformation = 'w_20', string $format = 'png'): array
    {
        $socket = stream_socket_client('unix://' . $this->root . '/pool.sock', $errno, $error, 1);
        self::assertIsResource($socket, $error);
        stream_set_timeout($socket, 4);
        $this->sockets[] = $socket;
        $destination = tempnam($this->root . '/cache', '.tmp-');
        fwrite($socket, json_encode(['protocol' => 1, 'publicId' => $publicId,
            'identity' => (new LocalSource($this->root))->resolve($publicId)->identity,
            'transformation' => $transformation, 'format' => $format, 'destination' => $destination], JSON_THROW_ON_ERROR) . "\n");
        return [$socket, $destination];
    }

    private function receive($socket): array
    {
        $line = fgets($socket);
        self::assertIsString($line, 'Missing production protocol response');
        $reply = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $reply['protocol']);
        return $reply;
    }

    private function residentMiB(int $pid): int
    {
        $status = @file_get_contents('/proc/' . $pid . '/status');
        self::assertIsString($status, 'Worker RSS unavailable on this platform');
        self::assertSame(1, preg_match('/VmRSS:\s+(\d+)/', $status, $match));
        return intdiv((int) $match[1], 1024);
    }

    public function testReadyCrashLoopBackoffIsExponentialCappedAndRecovers(): void
    {
        $this->start();
        foreach ([100, 200, 400, 800, 1600, 3200, 5000, 5000] as $index => $delay) {
            $ready = $this->await('worker_ready', $index + 1);
            self::assertTrue(posix_kill($ready['pid'], SIGKILL));
            $backoff = $this->await('worker_backoff', $index + 1);
            self::assertSame($delay, $backoff['delay_ms']);
            self::assertSame('worker_exit', $backoff['reason']);
            self::assertFalse(posix_kill($ready['pid'], 0), 'Reap must precede backoff');
            $next = $this->await('worker_start', $index + 2);
            self::assertGreaterThanOrEqual($delay * 1_000_000, $next['monotonic_ns'] - $backoff['monotonic_ns']);
            self::assertLessThan(($delay + 2000) * 1_000_000, $next['monotonic_ns'] - $backoff['monotonic_ns']);
        }
        $this->await('worker_ready', 9);
        [$socket, $destination] = $this->send();
        self::assertSame(200, $this->receive($socket)['status']);
        self::assertSame('image/png', getimagesize($destination)['mime']);
        $this->pool->signal(SIGTERM);
        self::assertSame(0, $this->pool->wait(), $this->pool->getErrorOutput());
        self::assertCount(9, $this->events('worker_start'));
    }

    public function testFailedExecutableKeepsQueueDeadlineAndStopsDuringBackoff(): void
    {
        // Actual failed process startup, not a substitute JSON worker protocol.
        $this->start(['EVATHUMBER_PHP_BINARY' => '/nonexistent/evathumber-php']);
        $this->await('worker_backoff', 3);
        [$socket, $destination] = $this->send();
        $started = hrtime(true);
        $reply = $this->receive($socket);
        self::assertSame(503, $reply['status']);
        self::assertSame('queue_timeout', $reply['error']);
        self::assertGreaterThanOrEqual(350, (hrtime(true) - $started) / 1e6);
        self::assertLessThan(1400, (hrtime(true) - $started) / 1e6);
        self::assertSame('', file_get_contents($destination));
        $this->await('worker_backoff', 5);
        $starts = count($this->events('worker_start'));
        $started = hrtime(true);
        $this->pool->signal(SIGTERM);
        self::assertSame(0, $this->pool->wait(), $this->pool->getErrorOutput());
        self::assertLessThan(1000, (hrtime(true) - $started) / 1e6);
        self::assertCount($starts, $this->events('worker_start'));
    }

    public function testStoppingExpiresAndReapsStuckActiveWorkAndRejectsQueuedWork(): void
    {
        $this->start();
        $pid = $this->await('worker_ready', 1)['pid'];
        self::assertTrue(posix_kill($pid, SIGSTOP));
        [$active, $destination] = $this->send();
        $this->await('job_started', 1);
        [$queued, $queuedDestination] = $this->send();
        $this->await('job_queued', 2);
        $this->pool->signal(SIGTERM);
        $reply = $this->receive($queued);
        self::assertSame(503, $reply['status']);
        self::assertSame('pool_stopping', $reply['error']);
        $reply = $this->receive($active);
        self::assertSame(504, $reply['status']);
        self::assertSame('processing_timeout', $reply['error']);
        self::assertFalse(posix_kill($pid, 0));
        self::assertSame('', file_get_contents($destination));
        self::assertSame('', file_get_contents($queuedDestination));
        self::assertSame(0, $this->pool->wait(), $this->pool->getErrorOutput());
        self::assertCount(1, $this->events('worker_start'));
        self::assertSame([], glob($this->root . '/stage-*'));
        self::assertFileDoesNotExist($this->root . '/pool.sock');
    }

    public function testLinuxParentDeathKillsBusyStoppedWorkerAndReleasesOwnership(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') { self::markTestSkipped('Linux PR_SET_PDEATHSIG'); }
        // Become the orphan reaper rather than relying on the container PID 1.
        $libc = \FFI::cdef('int prctl(int option, unsigned long a2, unsigned long a3, unsigned long a4, unsigned long a5);');
        self::assertSame(0, $libc->prctl(36, 1, 0, 0, 0));
        $pid = null;
        try {
            $this->start();
            $pid = $this->await('worker_ready', 1)['pid'];
            self::assertTrue(posix_kill($pid, SIGSTOP));
            [$active, $destination] = $this->send();
            $this->await('job_started', 1);
            file_put_contents($this->root . '/stage-0', 'abandoned-stage');
            $this->pool->signal(SIGKILL);
            $this->pool->wait();
            $deadline = hrtime(true) + 2_000_000_000;
            do {
                $reaped = pcntl_waitpid($pid, $status, WNOHANG);
                if ($reaped === $pid) { break; }
                usleep(5000);
            } while (hrtime(true) < $deadline);
            self::assertSame($pid, $reaped, 'Stopped busy worker survived parent death');
            self::assertTrue(pcntl_wifsignaled($status));
            self::assertSame(SIGKILL, pcntl_wtermsig($status));
            self::assertFalse(posix_kill($pid, 0));
            self::assertSame('', file_get_contents($destination));
            self::assertFalse(fgets($active), 'Orphan must not retain client descriptors');
            $pid = null;
            $this->start();
            $this->await('worker_ready', 1);
            self::assertFileDoesNotExist($this->root . '/stage-0');
            [$socket, $output] = $this->send();
            self::assertSame(200, $this->receive($socket)['status']);
            self::assertSame('image/png', getimagesize($output)['mime']);
            $this->pool->signal(SIGTERM);
            self::assertSame(0, $this->pool->wait(), $this->pool->getErrorOutput());
        } finally {
            if ($pid !== null && posix_kill($pid, 0)) { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
            $libc->prctl(36, 0, 0, 0, 0);
        }
    }

    public function testOverBudgetWorkerFinishesItsJobAndIsRecycledOnlyWhileIdle(): void
    {
        // The RSS guard itself is Linux-only: it samples /proc, so this cannot be
        // observed on a host without it.
        if (PHP_OS_FAMILY !== 'Linux') { self::markTestSkipped('Worker RSS sampling reads /proc'); }
        // A worker never gives memory back between jobs, so a long-lived one drifts
        // up to the high water mark of the largest source it has served and then
        // crosses WORKER_RSS_MIB on a *later*, unrelated job. Killing the worker
        // there answers 503 worker_memory_limit for work that would have succeeded:
        // that is what the README q_auto example hit on the progressive 17.9 MPix
        // demo.jpg, which needs 178 MiB against the 192 MiB default.
        //
        // Calibrate from the worker itself instead of hardcoding megabytes: measure
        // its idle RSS and its RSS after one large transform, then put the cap
        // strictly between the two.
        Image::black(4000, 3000, ['bands' => 3])->jpegsave($this->root . '/big.jpg', ['interlace' => true]);
        // The first pool only measures: a cap high enough that the supervisor cannot
        // recycle the worker out from under the sampling.
        $this->start(['EVATHUMBER_TIMEOUT' => '10', 'EVATHUMBER_WORKER_RSS_MIB' => '4096']);
        $pid = $this->await('worker_ready', 1)['pid'];
        $idle = $this->residentMiB($pid);
        [$socket, $destination] = $this->send('big', 'w_600', 'jpg');
        self::assertSame(200, $this->receive($socket)['status']);
        self::assertSame('image/jpeg', getimagesize($destination)['mime']);
        self::assertSame(600, getimagesize($destination)[0]);
        $retained = $this->residentMiB($pid);
        $this->pool->signal(SIGTERM);
        self::assertSame(0, $this->pool->wait(), $this->pool->getErrorOutput());
        self::assertGreaterThan($idle, $retained, 'A worker must be observed retaining memory between jobs');

        $this->start(['EVATHUMBER_WORKER_RSS_MIB' => (string) intdiv($idle + $retained, 2), 'EVATHUMBER_TIMEOUT' => '10']);
        $this->await('worker_ready', 1);
        [$socket, $destination] = $this->send('big', 'w_600', 'jpg');
        self::assertSame(200, $this->receive($socket)['status'], 'A running job must not be killed for RSS');
        self::assertSame('image/jpeg', getimagesize($destination)['mime']);
        self::assertSame(600, getimagesize($destination)[0]);
        $stop = $this->await('worker_stop', 1);
        self::assertSame('worker_memory_limit', $stop['reason'], 'An over budget worker must be recycled between jobs');
        $this->await('worker_start', 2);
        self::assertSame([], $this->events('worker_backoff'), 'A planned retirement must not delay the replacement');
        $this->pool->signal(SIGTERM);
        self::assertSame(0, $this->pool->wait(), $this->pool->getErrorOutput());
    }

    protected function tearDown(): void
    {
        $this->pool?->stop(1);
        foreach ($this->sockets as $socket) { if (is_resource($socket)) { fclose($socket); } }
        if (!isset($this->root)) { return; }
        foreach (glob($this->root . '/cache/.*') as $file) { if (is_file($file)) { unlink($file); } }
        foreach (glob($this->root . '/cache/*') as $file) { unlink($file); }
        rmdir($this->root . '/cache');
        foreach (glob($this->root . '/*') as $file) { unlink($file); }
        rmdir($this->root);
    }
}
