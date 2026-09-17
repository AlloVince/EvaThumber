<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Source\LocalSource;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PoolQueueTest extends TestCase
{
    public static function queueLimits(): iterable
    {
        yield 'default' => [4, 1000];
        yield 'configured' => [2, 400];
    }

    #[DataProvider('queueLimits')]
    public function testQueueCapacityDeadlineAndHardTimeoutRecover(int $queueSize, int $queueMilliseconds): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('Pool lifecycle requires pcntl and posix.');
        }
        // Keep the Unix socket pathname below macOS sockaddr_un limits.
        $root = '/tmp/ep-' . bin2hex(random_bytes(5));
        mkdir($root); mkdir($root . '/cache');
        Image::black(40, 30, ['bands' => 3])->jpegsave($root . '/foo.jpg');
        $identity = (new LocalSource($root))->resolve('foo')->identity;
        $pool = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/pool.php'], env: [
            'EVATHUMBER_SOURCE' => $root, 'EVATHUMBER_CACHE' => $root . '/cache',
            'EVATHUMBER_POOL_SOCKET' => $root . '/pool.sock', 'EVATHUMBER_POOL_SIZE' => '1',
            'EVATHUMBER_TIMEOUT' => '3', 'EVATHUMBER_WORKER_RSS_MIB' => '192',
            'EVATHUMBER_POOL_QUEUE_SIZE' => (string) $queueSize, 'EVATHUMBER_POOL_QUEUE_MS' => (string) $queueMilliseconds,
            'EVATHUMBER_PHP_BINARY' => PHP_BINARY,
        ], timeout: 15);
        $sockets = [];
        $send = static function (string $version) use ($root, $identity, &$sockets) {
            $socket = stream_socket_client('unix://' . $root . '/pool.sock', $errno, $error, 1);
            self::assertIsResource($socket, $error);
            stream_set_timeout($socket, 5);
            $sockets[] = $socket;
            $destination = tempnam($root . '/cache', '.tmp-');
            fwrite($socket, json_encode(['protocol' => 1, 'publicId' => 'foo', 'identity' => $identity,
                'transformation' => 'w_' . $version, 'format' => 'png', 'destination' => $destination], JSON_THROW_ON_ERROR) . "\n");
            return $socket;
        };
        $receive = static function ($socket): array {
            $line = fgets($socket);
            self::assertIsString($line, 'Pool response missing');
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        };
        try {
            $pool->start();
            self::assertTrue($pool->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'worker_ready')));
            preg_match('/"event":"worker_ready","pid":(\d+)/', $pool->getErrorOutput(), $match);
            $pid = (int) $match[1];
            self::assertTrue(posix_kill($pid, SIGSTOP));
            $active = $send('20');
            self::assertTrue($pool->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'job_started')));
            $start = hrtime(true);
            $queued = [];
            for ($i = 0; $i < $queueSize; ++$i) { $queued[] = $send((string) (21 + $i)); }
            $overflow = $receive($send('25'));
            self::assertSame(503, $overflow['status']);
            self::assertSame('processor_busy', $overflow['error']);
            self::assertLessThan(800, (hrtime(true) - $start) / 1e6);
            foreach ($queued as $socket) {
                $reply = $receive($socket);
                self::assertSame(503, $reply['status']);
                self::assertSame('queue_timeout', $reply['error']);
            }
            self::assertGreaterThanOrEqual($queueMilliseconds - 50, (hrtime(true) - $start) / 1e6);
            self::assertLessThan($queueMilliseconds + 1200, (hrtime(true) - $start) / 1e6);
            self::assertSame(504, $receive($active)['status']);
            self::assertFalse(posix_kill($pid, 0), 'Timed out worker was not reaped');
            self::assertSame(200, $receive($send('26'))['status']);
            self::assertSame([], glob($root . '/stage-*'));
            $pool->signal(SIGTERM);
            self::assertSame(0, $pool->wait(), $pool->getErrorOutput());
        } finally {
            foreach ($sockets as $socket) { fclose($socket); }
            $pool->stop(1);
            foreach (new \DirectoryIterator($root . '/cache') as $file) { if (!$file->isDot()) { unlink($file->getPathname()); } }
            rmdir($root . '/cache');
            foreach (new \DirectoryIterator($root) as $file) { if (!$file->isDot()) { unlink($file->getPathname()); } }
            rmdir($root);
        }
    }
}
