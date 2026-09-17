<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Cache\DiskCache;
use PHPUnit\Framework\TestCase;

final class CacheLifecycleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/eva-cache-' . bin2hex(random_bytes(8));
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

    public function testFullCacheEvictsOldestEntryAndContinuesServing(): void
    {
        $cache = new DiskCache($this->directory, 8, 2);
        $oldest = $cache->remember('oldest', 'png', self::produce(...));
        $oldestPath = $oldest->path;
        touch($oldestPath, 100);
        unset($oldest);
        $recent = $cache->remember('recent', 'png', self::produce(...));
        $recentPath = $recent->path;
        touch($recentPath, 200);
        unset($recent);

        $new = $cache->remember('new', 'png', self::produce(...));

        self::assertFalse($new->hit);
        self::assertFileDoesNotExist($oldestPath);
        self::assertFileExists($recentPath);
        self::assertSame('data', file_get_contents($new->path));
        self::assertCount(2, glob($this->directory . '/*.png'));
        self::assertSame([], glob($this->directory . '/.tmp-*'));
        self::assertTrue($cache->remember('new', 'png', static function (): void {
            self::fail('Cache hit must not invoke producer.');
        })->hit);
    }

    public function testFailedProducerPreservesExistingEntry(): void
    {
        $cache = new DiskCache($this->directory, 8, 1);
        $existing = $cache->remember('existing', 'png', self::produce(...));
        $existingPath = $existing->path;
        unset($existing);
        try {
            $cache->remember('failed', 'png', static function (string $path): void {
                file_put_contents($path, 'partial');
                throw new \RuntimeException('Producer failed');
            });
            self::fail('Expected producer failure');
        } catch (\RuntimeException $error) {
            self::assertSame('Producer failed', $error->getMessage());
        }
        self::assertSame('data', file_get_contents($existingPath));
        self::assertSame([], glob($this->directory . '/.tmp-*'));
        self::assertCount(1, glob($this->directory . '/*.png'));
        self::assertFalse($cache->remember('retry', 'png', self::produce(...))->hit);
    }

    public function testEvictionSkipsLeasedOldestEntry(): void
    {
        $cache = new DiskCache($this->directory, 8, 2);
        $leased = $cache->remember('leased', 'png', self::produce(...));
        touch($leased->path, 100);
        $idle = $cache->remember('idle', 'png', self::produce(...));
        $idlePath = $idle->path;
        touch($idlePath, 200);
        unset($idle);
        $next = $cache->remember('next', 'png', self::produce(...));
        self::assertFileExists($leased->path);
        self::assertFileDoesNotExist($idlePath);
        self::assertSame('data', file_get_contents($next->path));
    }

    private static function produce(string $path): void
    {
        file_put_contents($path, 'data');
    }
}
