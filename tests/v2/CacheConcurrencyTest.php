<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Cache\DiskCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CacheConcurrencyTest extends TestCase
{
    public function testAdmissionSlotsBoundMissesAndRecoverAfterProcessDeath(): void
    {
        $directory = sys_get_temp_dir() . '/eva-admission-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $cache = new DiskCache($directory);
        $cached = $cache->remember('cached', 'png', static fn (string $path) => file_put_contents($path, 'cached'));
        $input = new \Symfony\Component\Process\InputStream();
        // Seven occupied waiter slots plus a real producer fill admission.
        // A further miss must fail before entering the publication wait loop.
        $holder = new Process([PHP_BINARY, '-r', <<<'PHP'
require $argv[1];
$cache = new \EvaThumber\Cache\DiskCache($argv[2]);
$leases = [];
for ($slot = 0; $slot < 7; ++$slot) {
    $lease = fopen($argv[2] . '/.admission-' . $slot . '.lock', 'c');
    if (!flock($lease, LOCK_EX | LOCK_NB)) { exit(3); }
    $leases[] = $lease;
}
$cache->remember('producer', 'png', static function (string $path): void {
    file_put_contents($path, 'partial');
    echo "FULL\n";
    fgets(STDIN);
});
PHP, dirname(__DIR__, 2) . '/vendor/autoload.php', $directory], input: $input, timeout: 5);
        try {
            $holder->start();
            self::assertTrue($holder->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "FULL\n")));
            self::assertCount(8, glob($directory . '/.admission-*.lock'));
            self::assertTrue($cache->remember('cached', 'png', static function (): void {
                self::fail('Hit must bypass full admission.');
            })->hit);
            $start = hrtime(true);
            try {
                $cache->remember('overflow', 'png', static function (): void {
                    self::fail('Overflow must not run a producer.');
                });
                self::fail('Full admission must reject.');
            } catch (\EvaThumber\Exception\ImageException $error) {
                self::assertSame(503, $error->status);
                self::assertSame('processor_busy', $error->error);
            }
            self::assertLessThan(200, (hrtime(true) - $start) / 1e6);
            $holder->stop(0, 9);
            self::assertFalse($holder->isRunning());
            $recovered = $cache->remember('overflow', 'png', static fn (string $path) => file_put_contents($path, 'recovered'));
            self::assertFalse($recovered->hit);
            self::assertSame('recovered', file_get_contents($recovered->path));
            self::assertSame([], glob($directory . '/.tmp-*'));
            // All slots, not just the first one, must be available after SIGKILL.
            for ($slot = 0; $slot < 8; ++$slot) {
                $lease = fopen($directory . '/.admission-' . $slot . '.lock', 'c');
                self::assertNotFalse($lease);
                self::assertTrue(flock($lease, LOCK_EX | LOCK_NB));
                fclose($lease);
            }
        } finally {
            $holder->stop(0);
            $input->close();
            unset($cached, $recovered);
            foreach (new \DirectoryIterator($directory) as $file) {
                if (!$file->isDot()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($directory);
        }
    }

    public function testBusyAdmissionHasDeadlineAndHitsBypassIt(): void
    {
        $directory = sys_get_temp_dir() . '/eva-concurrency-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $cache = new DiskCache($directory);
        $cached = $cache->remember('cached', 'png', static fn (string $path) => file_put_contents($path, 'cached'));
        $lock = fopen($directory . '/.publish.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $consumer = new Process([PHP_BINARY, '-r', <<<'PHP'
require $argv[1];
$cache = new \EvaThumber\Cache\DiskCache($argv[2]);
$fail = static function (string $path): void { throw new \RuntimeException('Producer must not run.'); };
$entry = $cache->remember('cached', 'png', $fail);
echo ($entry->hit ? 'HIT' : 'MISS') . "\n";
$start = hrtime(true);
try {
    $cache->remember('busy', 'png', $fail);
    exit(2);
} catch (\EvaThumber\Exception\ImageException $error) {
    echo json_encode(['error' => $error->getMessage(), 'elapsed_ms' => (hrtime(true) - $start) / 1e6]);
}
PHP, dirname(__DIR__, 2) . '/vendor/autoload.php', $directory], timeout: 5);
        try {
            self::assertSame(0, $consumer->run(), $consumer->getErrorOutput());
            self::assertStringStartsWith("HIT\n", $consumer->getOutput());
            $result = json_decode(substr($consumer->getOutput(), 4), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('Image processor busy. Retry shortly.', $result['error']);
            self::assertGreaterThanOrEqual(240, $result['elapsed_ms']);
            self::assertLessThan(1500, $result['elapsed_ms']);
            self::assertSame([], glob($directory . '/.tmp-*'));
            flock($lock, LOCK_UN);
            $retry = $cache->remember('busy', 'png', static fn (string $path) => file_put_contents($path, 'retry'));
            self::assertFalse($retry->hit);
            self::assertSame('retry', file_get_contents($retry->path));
        } finally {
            $consumer->stop(0);
            fclose($lock);
            unset($cached, $retry);
            foreach (new \DirectoryIterator($directory) as $file) {
                if (!$file->isDot()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($directory);
        }
    }

    public function testKilledProducerReleasesLockAndOrphanTemporaryIsCleaned(): void
    {
        $directory = sys_get_temp_dir() . '/eva-concurrency-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $input = new \Symfony\Component\Process\InputStream();
        $producer = new Process([PHP_BINARY, '-r', <<<'PHP'
require $argv[1];
(new \EvaThumber\Cache\DiskCache($argv[2]))->remember('same', 'png', static function (string $path): void {
    file_put_contents($path, 'partial');
    fwrite(STDOUT, "LOCKED\n");
    fgets(STDIN);
    throw new \RuntimeException('Must be killed before continuing.');
});
PHP, dirname(__DIR__, 2) . '/vendor/autoload.php', $directory], input: $input, timeout: 5);
        try {
            $producer->start();
            self::assertTrue($producer->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "LOCKED\n")));
            self::assertCount(1, glob($directory . '/.tmp-*'));
            self::assertSame([], glob($directory . '/*.png'));
            $producer->stop(0, 9);
            self::assertFalse($producer->isRunning());
            $entry = (new DiskCache($directory))->remember('same', 'png', static function (string $path) use ($directory): void {
                // Only the new producer's temporary remains after orphan cleanup.
                self::assertCount(1, glob($directory . '/.tmp-*'));
                file_put_contents($path, 'recovered');
            });
            self::assertFalse($entry->hit);
            self::assertSame('recovered', file_get_contents($entry->path));
            self::assertSame([], glob($directory . '/.tmp-*'));
        } finally {
            $producer->stop(0);
            $input->close();
            unset($entry);
            foreach (new \DirectoryIterator($directory) as $file) {
                if (!$file->isDot()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($directory);
        }
    }

    public function testConcurrentSameKeyMissReusesPublishedEntry(): void
    {
        $directory = sys_get_temp_dir() . '/eva-concurrency-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $consumer = new Process([PHP_BINARY, '-r', <<<'PHP'
require $argv[1];
fwrite(STDOUT, "READY\n");
$entry = (new \EvaThumber\Cache\DiskCache($argv[2]))->remember('same', 'png', static function (string $path): void {
    throw new \RuntimeException('Duplicate producer must not run.');
});
echo ($entry->hit ? 'HIT' : 'MISS') . ':' . file_get_contents($entry->path) . "\n";
PHP, dirname(__DIR__, 2) . '/vendor/autoload.php', $directory], timeout: 5);

        try {
            $entry = (new DiskCache($directory))->remember('same', 'png', static function (string $path) use ($consumer): void {
                $consumer->start();
                self::assertTrue($consumer->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "READY\n")));
                // Observe actual admission instead of spending the 250ms budget in a fixed sleep.
                $slot = fopen(dirname($path) . '/.admission-1.lock', 'c');
                self::assertNotFalse($slot);
                $waiting = false;
                $deadline = hrtime(true) + 1_000_000_000;
                try {
                    do {
                        if (!flock($slot, LOCK_EX | LOCK_NB)) {
                            $waiting = true;
                            break;
                        }
                        flock($slot, LOCK_UN);
                        usleep(1000);
                    } while ($consumer->isRunning() && hrtime(true) < $deadline);
                } finally {
                    fclose($slot);
                }
                self::assertTrue($waiting, 'Consumer must hold admission while waiting for publication: ' . $consumer->getErrorOutput());
                file_put_contents($path, 'published-once');
            });
            self::assertFalse($entry->hit);
            self::assertSame(0, $consumer->wait(), $consumer->getErrorOutput());
            self::assertSame("READY\nHIT:published-once\n", $consumer->getOutput());
            self::assertCount(1, glob($directory . '/*.png'));
            self::assertSame([], glob($directory . '/.tmp-*'));
        } finally {
            $consumer->stop(0);
            unset($entry);
            foreach (new \DirectoryIterator($directory) as $file) {
                if (!$file->isDot()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($directory);
        }
    }
}
