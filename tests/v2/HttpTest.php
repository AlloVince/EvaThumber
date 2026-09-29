<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Http\Kernel;
use EvaThumber\Http\Settings;
use EvaThumber\Version;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HttpTest extends TestCase
{
    public function testEncodedAssetIdentitySurvivesSingleEntryEviction(): void
    {
        $root = sys_get_temp_dir() . '/eva-http-encoded-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/images');
        mkdir($root . '/cache');
        try {
            Image::black(20, 10, ['bands' => 3])->pngsave($root . '/images/a b.png');
            Image::black(20, 10, ['bands' => 3])->newFromImage([255, 255, 255])->pngsave($root . '/images/a+b.png');
            $kernel = new Kernel(new Settings($root . '/images', $root . '/cache', cacheEntries: 1));
            $bodies = [];
            $etags = [];
            foreach (['a%20b', 'a%20b', 'a%2Bb', 'a%20b'] as $index => $asset) {
                $response = $kernel->handle(Request::create('/image/upload/w_10/' . $asset . '.png'));
                self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
                self::assertSame($index === 1 ? 'HIT' : 'MISS', $response->headers->get('X-EvaThumber-Cache'));
                $etags[] = $response->headers->get('ETag');
                ob_start();
                try {
                    $response->sendContent();
                    $bodies[] = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                self::assertCount(1, glob($root . '/cache/*.png'));
                self::assertSame([], glob($root . '/cache/.tmp-*'));
            }
            self::assertNotEmpty($bodies[0]);
            self::assertSame($bodies[0], $bodies[1]);
            self::assertNotSame($bodies[0], $bodies[2]);
            self::assertSame($bodies[0], $bodies[3]);
            self::assertNotSame($etags[0], $etags[2]);
            self::assertSame($etags[0], $etags[3]);
        } finally {
            unset($response);
            foreach (['cache', 'images'] as $folder) {
                foreach (new \DirectoryIterator($root . '/' . $folder) as $file) {
                    if (!$file->isDot()) {
                        unlink($file->getPathname());
                    }
                }
                rmdir($root . '/' . $folder);
            }
            rmdir($root);
        }
    }

    public function testBusyAdmissionReturnsRetryAfterAndRecovers(): void
    {
        $root = sys_get_temp_dir() . '/eva-http-busy-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/images');
        mkdir($root . '/cache');
        $lock = fopen($root . '/cache/.publish.lock', 'c');
        self::assertNotFalse($lock);
        try {
            Image::black(20, 10, ['bands' => 3])->pngsave($root . '/images/demo.png');
            $kernel = new Kernel(new Settings($root . '/images', $root . '/cache'));
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            $busy = $kernel->handle(Request::create('/image/upload/w_10/demo.png'));
            self::assertSame(503, $busy->getStatusCode());
            self::assertSame('cache_publish_timeout', json_decode($busy->getContent(), true)['error']);
            self::assertSame('1', $busy->headers->get('Retry-After'));
            self::assertTrue($busy->headers->hasCacheControlDirective('no-store'));
            self::assertSame([], glob($root . '/cache/.tmp-*'));
            flock($lock, LOCK_UN);
            $response = $kernel->handle(Request::create('/image/upload/w_10/demo.png'));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('MISS', $response->headers->get('X-EvaThumber-Cache'));
        } finally {
            fclose($lock);
            unset($response);
            foreach (['cache', 'images'] as $folder) {
                foreach (new \DirectoryIterator($root . '/' . $folder) as $file) {
                    if (!$file->isDot()) {
                        unlink($file->getPathname());
                    }
                }
                rmdir($root . '/' . $folder);
            }
            rmdir($root);
        }
    }

    public function testResponseLeaseSurvivesUntilBodyIsSent(): void
    {
        $root = sys_get_temp_dir() . '/eva-http-lease-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/images');
        mkdir($root . '/cache');
        try {
            Image::black(20, 10, ['bands' => 3])->pngsave($root . '/images/demo.png');
            $kernel = new Kernel(new Settings($root . '/images', $root . '/cache', cacheEntries: 1));
            $first = $kernel->handle(Request::create('/image/upload/w_10/demo.png'));
            self::assertSame(200, $first->getStatusCode());
            $files = glob($root . '/cache/*.png');
            self::assertCount(1, $files);
            $expected = file_get_contents($files[0]);

            $blocked = $kernel->handle(Request::create('/image/upload/w_5/demo.png'));
            self::assertSame(503, $blocked->getStatusCode());
            self::assertSame('cache_busy', json_decode($blocked->getContent(), true)['error']);
            self::assertSame('1', $blocked->headers->get('Retry-After'));
            self::assertFileExists($files[0]);
            self::assertSame([], glob($root . '/cache/.tmp-*'));

            ob_start();
            try {
                $first->sendContent();
                $actual = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            self::assertSame($expected, $actual);
            // Keep the response object alive: sendContent(), not destruction, releases it.
            $next = $kernel->handle(Request::create('/image/upload/w_5/demo.png'));
            self::assertSame(200, $next->getStatusCode());
            self::assertFileDoesNotExist($files[0]);
        } finally {
            unset($first, $next);
            foreach (['cache', 'images'] as $folder) {
                foreach (new \DirectoryIterator($root . '/' . $folder) as $file) {
                    if (!$file->isDot()) {
                        unlink($file->getPathname());
                    }
                }
                rmdir($root . '/' . $folder);
            }
            rmdir($root);
        }
    }

    public function testDeliveryIdentityAndAnalyticsCompatibility(): void
    {
        $root = sys_get_temp_dir() . '/eva-http-url-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/images');
        mkdir($root . '/cache');
        try {
            Image::black(20, 10, ['bands' => 3])->pngsave($root . '/images/demo.png');
            $kernel = new Kernel(new Settings($root . '/images', $root . '/cache'));
            $first = $kernel->handle(Request::create('/cloud/image/upload/c_fit,w_10/v123/demo.jpg'));
            self::assertSame(200, $first->getStatusCode());
            self::assertSame('image/jpeg', $first->headers->get('Content-Type'));
            $equivalent = $kernel->handle(Request::create('/cloud/image/upload/w_10,c_fit/v123/demo.jpeg?_a=sdk&_i=analytics'));
            self::assertSame(200, $equivalent->getStatusCode());
            self::assertSame('HIT', $equivalent->headers->get('X-EvaThumber-Cache'));
            self::assertSame($first->headers->get('ETag'), $equivalent->headers->get('ETag'));
            $version = $kernel->handle(Request::create('/cloud/image/upload/c_fit,w_10/v124/demo.jpg'));
            self::assertSame(200, $version->getStatusCode());
            self::assertSame('MISS', $version->headers->get('X-EvaThumber-Cache'));
            self::assertNotSame($first->headers->get('ETag'), $version->headers->get('ETag'));
            self::assertFalse($version->headers->hasCacheControlDirective('immutable'));
            self::assertSame(400, $kernel->handle(Request::create('/image/upload/demo.jpg?signature=unsupported'))->getStatusCode());
            self::assertSame(400, $kernel->handle(Request::create('/image/upload/demo.jpg?_a[]=invalid'))->getStatusCode());
            self::assertSame(414, $kernel->handle(Request::create('/image/upload/demo.jpg?_a=' . str_repeat('x', 4096)))->getStatusCode());
        } finally {
            unset($first, $equivalent, $version);
            foreach (['cache', 'images'] as $folder) {
                foreach (new \DirectoryIterator($root . '/' . $folder) as $file) {
                    if (!$file->isDot()) {
                        unlink($file->getPathname());
                    }
                }
                rmdir($root . '/' . $folder);
            }
            rmdir($root);
        }
    }

    public function testTransformCacheAndConditionalDelivery(): void
    {
        $root = sys_get_temp_dir() . '/eva-http-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/images');
        mkdir($root . '/cache');
        try {
            Image::black(200, 100, ['bands' => 3])->pngsave($root . '/images/demo.png');
            $kernel = new Kernel(new Settings($root . '/images', $root . '/cache'));
            $request = Request::create('/image/upload/c_fill,w_30,h_30,f_auto/demo.jpg');
            $request->headers->set('Accept', 'image/webp,image/jpeg;q=0.5');
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame('image/webp', $response->headers->get('Content-Type'));
            self::assertSame('Accept', $response->headers->get('Vary'));
            self::assertSame('MISS', $response->headers->get('X-EvaThumber-Cache'));
            $hit = $kernel->handle($request);
            self::assertSame('HIT', $hit->headers->get('X-EvaThumber-Cache'));
            $combinedRequest = Request::create('/image/upload/c_fill,w_30,h_30/q_80,f_auto/demo.jpg');
            $combinedRequest->headers->set('Accept', 'image/webp');
            $combined = $kernel->handle($combinedRequest);
            self::assertSame(200, $combined->getStatusCode());
            self::assertSame('MISS', $combined->headers->get('X-EvaThumber-Cache'));
            foreach (['q_80/f_auto', 'f_auto/q_80'] as $delivery) {
                $splitRequest = Request::create('/image/upload/c_fill,w_30,h_30/' . $delivery . '/demo.jpg');
                $splitRequest->headers->set('Accept', 'image/webp');
                $split = $kernel->handle($splitRequest);
                self::assertSame(200, $split->getStatusCode());
                self::assertSame('image/webp', $split->headers->get('Content-Type'));
                self::assertSame('Accept', $split->headers->get('Vary'));
                self::assertSame('HIT', $split->headers->get('X-EvaThumber-Cache'));
                self::assertSame($combined->headers->get('ETag'), $split->headers->get('ETag'));
            }
            $request->headers->set('If-None-Match', $response->headers->get('ETag'));
            self::assertSame(304, $kernel->handle($request)->getStatusCode());
            self::assertSame(400, $kernel->handle(Request::create('/image/upload/e_unknown/demo.jpg'))->getStatusCode());
            self::assertSame(200, $kernel->handle(Request::create('/healthz'))->getStatusCode());
        } finally {
            foreach (['cache', 'images'] as $folder) {
                foreach (new \DirectoryIterator($root . '/' . $folder) as $file) {
                    if (!$file->isDot()) {
                        unlink($file->getPathname());
                    }
                }
                rmdir($root . '/' . $folder);
            }
            rmdir($root);
        }
    }

    public function testReadinessReflectsSourceAndCacheAvailability(): void
    {
        $root = sys_get_temp_dir() . '/eva-readyz-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/images');
        mkdir($root . '/cache');
        try {
            $kernel = new Kernel(new Settings($root . '/images', $root . '/cache'));
            $ready = $kernel->handle(Request::create('/readyz'));
            self::assertSame(200, $ready->getStatusCode());
            self::assertSame(['status' => 'ready'], json_decode((string) $ready->getContent(), true));
            self::assertStringContainsString('no-store', (string) $ready->headers->get('Cache-Control'));
            // Liveness never depends on the transform path.
            self::assertSame(200, $kernel->handle(Request::create('/healthz'))->getStatusCode());
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                // Root bypasses the mode bits, so a chmod cannot make the cache
                // unwritable here. The production image is non-root, and
                // tests/product-acceptance.php proves this against a real read-only mount.
                self::markTestSkipped('Permission-based unwritable cache requires a non-root user.');
            }
            chmod($root . '/cache', 0500);
            $unready = $kernel->handle(Request::create('/readyz'));
            self::assertSame(503, $unready->getStatusCode());
            self::assertSame(['source' => true, 'cache' => false], json_decode((string) $unready->getContent(), true)['checks']);
            self::assertSame(200, $kernel->handle(Request::create('/healthz'))->getStatusCode());
            chmod($root . '/cache', 0700);
        } finally {
            @chmod($root . '/cache', 0700);
            rmdir($root . '/images');
            rmdir($root . '/cache');
            rmdir($root);
        }
    }

    public function testHealthzReportsTheReleasableVersion(): void
    {
        // The release workflow reads Version::VERSION and refuses to publish a
        // tag that disagrees with it, so a value it cannot turn into an image
        // tag has to fail here first, not on the tag push.
        self::assertMatchesRegularExpression(
            '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-([0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*))?$/',
            Version::VERSION,
        );
        $root = sys_get_temp_dir() . '/eva-healthz-version-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/images');
        mkdir($root . '/cache');
        try {
            $kernel = new Kernel(new Settings($root . '/images', $root . '/cache'));
            $response = $kernel->handle(Request::create('/healthz'));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(Version::VERSION, json_decode((string) $response->getContent(), true)['version']);
        } finally {
            rmdir($root . '/images');
            rmdir($root . '/cache');
            rmdir($root);
        }
    }

    public function testReadinessRequiresAReachablePool(): void
    {
        $root = sys_get_temp_dir() . '/eva-readyz-pool-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/images');
        mkdir($root . '/cache');
        try {
            $kernel = new Kernel(new Settings($root . '/images', $root . '/cache', poolSocket: $root . '/absent.sock'));
            $response = $kernel->handle(Request::create('/readyz'));
            self::assertSame(503, $response->getStatusCode());
            $body = json_decode((string) $response->getContent(), true);
            self::assertSame('unready', $body['status']);
            self::assertFalse($body['checks']['pool']);
            self::assertSame(200, $kernel->handle(Request::create('/healthz'))->getStatusCode());
        } finally {
            rmdir($root . '/images');
            rmdir($root . '/cache');
            rmdir($root);
        }
    }
}
