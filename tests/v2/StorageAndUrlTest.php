<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Cache\DiskCache;
use EvaThumber\Exception\ImageException;
use EvaThumber\Source\LocalSource;
use EvaThumber\Url\Parser;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\TestCase;

final class StorageAndUrlTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/eva-test-' . bin2hex(random_bytes(8));
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

    public function testUrlWithCloudVersionAndChain(): void
    {
        $url = (new Parser())->parse('/demo/image/upload/c_fill,w_100,h_50/a_90/v123/folder/photo.webp');
        self::assertSame('demo', $url->cloudName);
        self::assertSame('v123', $url->version);
        self::assertSame('folder/photo', $url->publicId);
        self::assertSame('webp', $url->format);
        self::assertSame('c_fill,h_50,w_100/a_90', $url->transformation->canonical());
        $plain = (new Parser())->parse('/image/upload/photo.jpg');
        self::assertNull($plain->cloudName);
        self::assertSame('photo', $plain->publicId);
    }

    public function testLocalResolutionAndMime(): void
    {
        Image::black(4, 4, ['bands' => 3])->pngsave($this->directory . '/photo.png');
        $source = (new LocalSource($this->directory))->resolve('photo');
        self::assertSame('png', $source->format);
        self::assertGreaterThan(0, $source->bytes);
        self::assertSame(realpath($this->directory . '/photo.png'), $source->path);
    }

    public function testLocalResolutionRejectsAmbiguity(): void
    {
        Image::black(4, 4, ['bands' => 3])->pngsave($this->directory . '/photo.png');
        Image::black(4, 4, ['bands' => 3])->jpegsave($this->directory . '/photo.jpg');
        $this->expectException(ImageException::class);
        (new LocalSource($this->directory))->resolve('photo');
    }

    public function testCacheHitBypassesProducer(): void
    {
        $calls = 0;
        $producer = static function (string $path) use (&$calls): void {
            ++$calls;
            file_put_contents($path, 'fixture');
        };
        $cache = new DiskCache($this->directory);
        $first = $cache->remember('source:transform:policy', 'png', $producer);
        $second = $cache->remember('source:transform:policy', 'png', $producer);
        self::assertFalse($first->hit);
        self::assertTrue($second->hit);
        self::assertSame($first->etag, $second->etag);
        self::assertSame(1, $calls);
        self::assertSame('fixture', file_get_contents($second->path));
    }

    public function testCacheCapacityDoesNotPublishPartialEntry(): void
    {
        $cache = new DiskCache($this->directory, 4, 1);
        try {
            $cache->remember('large', 'png', static function (string $path): void {
                file_put_contents($path, 'too large');
            });
            self::fail('Expected capacity error');
        } catch (ImageException $error) {
            self::assertSame('cache_full', $error->error);
            self::assertSame([], glob($this->directory . '/*.png'));
            self::assertSame([], glob($this->directory . '/.tmp-*'));
        }
    }
}
