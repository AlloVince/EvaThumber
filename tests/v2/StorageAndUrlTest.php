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

    public function testCloudinaryStyleAssetUrlCompatibility(): void
    {
        $cases = [
            // Delivery extension is separate from the asset public ID.
            ['/demo/image/upload/v123/folder/photo.webp', 'demo', 'v123', 'folder/photo', 'webp', ''],
            ['/image/upload/v456/folder/photo.jpeg', null, 'v456', 'folder/photo', 'jpg', ''],
            ['/demo/image/upload/folder/photo', 'demo', null, 'folder/photo', null, ''],
            // A version boundary protects transformation-like folder names.
            ['/demo/image/upload/w_100/v123/c_assets/photo.jpg', 'demo', 'v123', 'c_assets/photo', 'jpg', 'c_scale,w_100'],
            // Once public ID parsing begins, nested version-like names remain intact.
            ['/image/upload/v123/folder/v456/photo.png', null, 'v123', 'folder/v456/photo', 'png', ''],
            ['/image/upload/v123/folder/photo.original.jpg', null, 'v123', 'folder/photo.original', 'jpg', ''],
            ['/demo/image/upload/c_fill,w_100,h_50/a_90/v123/folder/photo.webp', 'demo', 'v123', 'folder/photo', 'webp', 'c_fill,h_50,w_100/a_90'],
            ['/image/upload/f_auto,q_80/v123/folder/summer%20photo.jpg', null, 'v123', 'folder/summer photo', 'jpg', 'f_auto,q_80'],
        ];
        $parser = new Parser();
        foreach ($cases as [$path, $cloud, $version, $publicId, $format, $canonical]) {
            $url = $parser->parse($path);
            self::assertSame($cloud, $url->cloudName, $path);
            self::assertSame($version, $url->version, $path);
            self::assertSame($publicId, $url->publicId, $path);
            self::assertSame($format, $url->format, $path);
            self::assertSame($canonical, $url->transformation->canonical(), $path);
        }
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
