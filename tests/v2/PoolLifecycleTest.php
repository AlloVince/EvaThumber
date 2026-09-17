<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Source\LocalSource;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PoolLifecycleTest extends TestCase
{
    public static function failures(): iterable
    {
        yield 'removed destination' => ['removed'];
        yield 'replaced destination' => ['replaced'];
        yield 'disconnected client' => ['disconnected'];
        yield 'active worker killed with partial stage' => ['killed'];
        yield 'shutdown with active and queued jobs' => ['shutdown'];
    }

    #[DataProvider('failures')]
    public function testFailureDoesNotPublishAndReleasesResources(string $failure): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('Pool lifecycle requires pcntl and posix.');
        }
        $root = '/tmp/el-' . bin2hex(random_bytes(5));
        mkdir($root); mkdir($root . '/cache');
        Image::black(40, 30, ['bands' => 3])->jpegsave($root . '/foo.jpg');
        $identity = (new LocalSource($root))->resolve('foo')->identity;
        $pool = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/pool.php'], env: [
            'EVATHUMBER_SOURCE' => $root, 'EVATHUMBER_CACHE' => $root . '/cache',
            'EVATHUMBER_POOL_SOCKET' => $root . '/pool.sock', 'EVATHUMBER_POOL_SIZE' => '1',
            'EVATHUMBER_TIMEOUT' => '3', 'EVATHUMBER_WORKER_RSS_MIB' => '192',
            'EVATHUMBER_PHP_BINARY' => PHP_BINARY,
        ], timeout: 15);
        $sockets = [];
        $send = static function () use ($root, $identity, &$sockets): array {
            $socket = stream_socket_client('unix://' . $root . '/pool.sock', $errno, $error, 1);
            self::assertIsResource($socket, $error);
            stream_set_timeout($socket, 5);
            $sockets[] = $socket;
            $destination = tempnam($root . '/cache', '.tmp-');
            fwrite($socket, json_encode(['protocol' => 1, 'publicId' => 'foo', 'identity' => $identity,
                'transformation' => 'w_20', 'format' => 'png', 'destination' => $destination], JSON_THROW_ON_ERROR) . "\n");
            return [$socket, $destination];
        };
        $receive = static function ($socket): array {
            $line = fgets($socket);
            self::assertIsString($line, 'Pool response missing');
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        };
        $oldDestination = null;
        try {
            $pool->start();
            self::assertTrue($pool->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'worker_ready')));
            preg_match('/"event":"worker_ready","pid":(\d+)/', $pool->getErrorOutput(), $match);
            $pid = (int) $match[1];
            self::assertTrue(posix_kill($pid, SIGSTOP));
            [$active, $destination] = $send();
            self::assertTrue($pool->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'job_started')));
            if ($failure === 'removed' || $failure === 'replaced') {
                // Hold the unlinked inode so replacement cannot reuse its number.
                $oldDestination = fopen($destination, 'rb');
                unlink($destination);
                if ($failure === 'replaced') { file_put_contents($destination, 'replacement-owner'); }
                self::assertTrue(posix_kill($pid, SIGCONT));
                $reply = $receive($active);
                self::assertSame(503, $reply['status']);
                self::assertSame('cache_unavailable', $reply['error']);
                clearstatcache(true, $destination);
                if ($failure === 'removed') { self::assertFileDoesNotExist($destination); }
                else { self::assertSame('replacement-owner', file_get_contents($destination)); }
            } elseif ($failure === 'disconnected') {
                unlink($destination);
                fclose($active);
                self::assertTrue($pool->waitUntil(static fn (string $type, string $output): bool => str_contains($output, '"reason":"client_disconnected"')));
                self::assertFalse(posix_kill($pid, 0), 'Abandoned worker must be reaped');
                self::assertFileDoesNotExist($destination);
            } elseif ($failure === 'killed') {
                // Deterministic partial-stage boundary: no successful worker reply.
                file_put_contents($root . '/stage-0', 'partial-image');
                self::assertTrue(posix_kill($pid, SIGKILL));
                $reply = $receive($active);
                self::assertSame(503, $reply['status']);
                self::assertSame('worker_exit', $reply['error']);
                self::assertFalse(posix_kill($pid, 0));
                self::assertSame('', file_get_contents($destination));
            } else {
                [$queued, $queuedDestination] = $send();
                $pool->signal(SIGTERM);
                $reply = $receive($queued);
                self::assertSame(503, $reply['status']);
                self::assertSame('pool_stopping', $reply['error']);
                self::assertSame('', file_get_contents($queuedDestination));
                self::assertTrue(posix_kill($pid, SIGCONT));
                self::assertSame(200, $receive($active)['status']);
                self::assertSame('image/png', getimagesize($destination)['mime']);
                self::assertSame(0, $pool->wait(), $pool->getErrorOutput());
                self::assertFalse(posix_kill($pid, 0));
            }
            if ($failure !== 'shutdown') {
                [$recovery, $output] = $send();
                self::assertSame(200, $receive($recovery)['status']);
                self::assertSame('image/png', getimagesize($output)['mime']);
                $pool->signal(SIGTERM);
                self::assertSame(0, $pool->wait(), $pool->getErrorOutput());
            }
            self::assertSame([], glob($root . '/stage-*'));
            self::assertFileDoesNotExist($root . '/pool.sock');
        } finally {
            if (is_resource($oldDestination)) { fclose($oldDestination); }
            foreach ($sockets as $socket) { if (is_resource($socket)) { fclose($socket); } }
            $pool->stop(1);
            foreach (new \DirectoryIterator($root . '/cache') as $file) { if (!$file->isDot()) { unlink($file->getPathname()); } }
            rmdir($root . '/cache');
            foreach (new \DirectoryIterator($root) as $file) { if (!$file->isDot()) { unlink($file->getPathname()); } }
            rmdir($root);
        }
    }
}
