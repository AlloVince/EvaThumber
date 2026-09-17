<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Cache\DiskCache;
use EvaThumber\Exception\ImageException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class CachePublicationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/eva-publication-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if (!$file->isDot()) {
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public function testAtomicLinkAndCrashBeforeTemporaryUnlinkPreservePublishedEntry(): void
    {
        $input = new InputStream();
        $producer = new Process([PHP_BINARY, '-r', <<<'PHP'
require $argv[1];
(new \EvaThumber\Cache\DiskCache($argv[2]))->remember('same', 'png', static function (string $path): void {
    file_put_contents($path, 'complete-image');
    echo "PREPARED\n";
    fgets(STDIN);
}, static function (string $stage): void {
    if ($stage === 'cache_unlink') {
        echo "LINKED\n";
        fgets(STDIN);
    }
});
PHP, dirname(__DIR__, 2) . '/vendor/autoload.php', $this->directory], input: $input, timeout: 5);
        try {
            $producer->start();
            self::assertTrue($producer->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "PREPARED\n")));
            self::assertSame([], glob($this->directory . '/*.png'), 'No final name while producer writes.');
            $input->write("publish\n");
            self::assertTrue($producer->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "LINKED\n")));
            $files = glob($this->directory . '/*.png');
            $temporaries = glob($this->directory . '/.tmp-*');
            self::assertCount(1, $files);
            self::assertCount(1, $temporaries);
            self::assertSame('complete-image', file_get_contents($files[0]));
            self::assertSame(fileinode($files[0]), fileinode($temporaries[0]));
            self::assertSame(2, stat($files[0])['nlink']);
            $reader = fopen($files[0], 'rb');
            self::assertNotFalse($reader);
            try {
                self::assertFalse(flock($reader, LOCK_SH | LOCK_NB), 'Temporary lease also protects the published inode.');
            } finally {
                fclose($reader);
            }
            $producer->stop(0, 9);
            $cache = new DiskCache($this->directory);
            $entry = $cache->remember('same', 'png', static function (): void {
                self::fail('Published content must survive producer death.');
            });
            self::assertTrue($entry->hit);
            self::assertSame('complete-image', file_get_contents($entry->path));
            unset($entry);
            $other = $cache->remember('other', 'png', static fn (string $path) => file_put_contents($path, 'other'));
            self::assertSame([], glob($this->directory . '/.tmp-*'));
            self::assertSame('complete-image', file_get_contents($files[0]), 'Orphan cleanup only removes the temporary name.');
            clearstatcache(true, $files[0]);
            self::assertSame(1, stat($files[0])['nlink']);
            unset($other);
        } finally {
            $producer->stop(0);
            $input->close();
        }
    }

    public function testKeyWaitTimeoutIsAttributableAndReleasesAdmission(): void
    {
        $key = hash('sha256', 'same:png');
        $lock = fopen($this->directory . '/.key-' . substr($key, 0, 2) . '.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $start = hrtime(true);
            try {
                (new DiskCache($this->directory, waitMilliseconds: 30))->remember('same', 'png', static function (): void {
                    self::fail('Timed-out key must not run producer.');
                });
                self::fail('Expected key timeout.');
            } catch (ImageException $error) {
                self::assertSame(503, $error->status);
                self::assertSame('cache_key_timeout', $error->error);
            }
            self::assertGreaterThanOrEqual(25, (hrtime(true) - $start) / 1e6);
            self::assertLessThan(1000, (hrtime(true) - $start) / 1e6);
            $slot = fopen($this->directory . '/.admission-0.lock', 'c');
            self::assertNotFalse($slot);
            self::assertTrue(flock($slot, LOCK_EX | LOCK_NB));
            fclose($slot);
        } finally {
            fclose($lock);
        }
    }

    public function testPostProducerPublicationTimeoutCleansTemporaryAndRecovers(): void
    {
        $lock = fopen($this->directory . '/.publish.lock', 'c');
        self::assertNotFalse($lock);
        $cache = new DiskCache($this->directory);
        try {
            try {
                $cache->remember('same', 'png', static function (string $path) use ($lock): void {
                    file_put_contents($path, 'complete');
                    self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
                });
                self::fail('Expected publication timeout.');
            } catch (ImageException $error) {
                self::assertSame(503, $error->status);
                self::assertSame('cache_publish_timeout', $error->error);
            }
            self::assertSame([], glob($this->directory . '/.tmp-*'));
            self::assertSame([], glob($this->directory . '/*.png'));
            flock($lock, LOCK_UN);
            $entry = $cache->remember('same', 'png', static fn (string $path) => file_put_contents($path, 'retry'));
            self::assertFalse($entry->hit);
            self::assertSame('retry', file_get_contents($entry->path));
            unset($entry);
        } finally {
            fclose($lock);
        }
    }
}
