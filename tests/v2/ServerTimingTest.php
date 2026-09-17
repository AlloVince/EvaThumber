<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Http\Kernel;
use EvaThumber\Http\Settings;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ServerTimingTest extends TestCase
{
    public function testTimingIsOptInAndEnvironmentRequiresExplicitOne(): void
    {
        $previous = getenv('EVATHUMBER_SERVER_TIMING');
        try {
            foreach ([false, '0', 'true', '1'] as $value) {
                putenv($value === false ? 'EVATHUMBER_SERVER_TIMING' : 'EVATHUMBER_SERVER_TIMING=' . $value);
                self::assertSame($value === '1', Settings::fromEnvironment()->serverTiming);
            }
            $kernel = new Kernel(new Settings('/unused', '/unused'));
            foreach (['/healthz', '/invalid'] as $url) {
                self::assertFalse($kernel->handle(Request::create($url))->headers->has('Server-Timing'));
            }
        } finally {
            putenv($previous === false ? 'EVATHUMBER_SERVER_TIMING' : 'EVATHUMBER_SERVER_TIMING=' . $previous);
        }
    }

    public function testPersistentKernelDoesNotRetainTimingsAcrossMissHitAndError(): void
    {
        $root = sys_get_temp_dir() . '/eva-timing-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/images');
        mkdir($root . '/cache');
        try {
            Image::black(20, 10, ['bands' => 3])->pngsave($root . '/images/demo.png');
            $kernel = new Kernel(new Settings($root . '/images', $root . '/cache', serverTiming: true));
            foreach (['MISS', 'HIT'] as $expected) {
                $response = $kernel->handle(Request::create('/image/upload/w_10/demo.png'));
                self::assertSame(200, $response->getStatusCode());
                self::assertSame($expected, $response->headers->get('X-EvaThumber-Cache'));
                $header = $response->headers->get('Server-Timing');
                self::assertNotNull($header);
                preg_match_all('/([a-z_]+);dur=([0-9.]+)/', $header, $matches, PREG_SET_ORDER);
                $timings = [];
                foreach ($matches as $match) {
                    self::assertArrayNotHasKey($match[1], $timings);
                    $timings[$match[1]] = (float) $match[2];
                }
                self::assertGreaterThan(0, $timings['app']);
                $app = $timings['app'];
                unset($timings['app']);
                self::assertEqualsWithDelta($app, array_sum($timings), 1.0);
                if ($expected === 'MISS') {
                    self::assertArrayHasKey('processor', $timings);
                    self::assertArrayHasKey('cache_publish', $timings);
                    self::assertArrayHasKey('cache_unlink', $timings);
                } else {
                    self::assertArrayNotHasKey('processor', $timings);
                    self::assertArrayNotHasKey('cache_publish', $timings);
                }
                unset($response);
            }
            $error = $kernel->handle(Request::create('/image/upload/e_unknown/demo.png'));
            self::assertSame(400, $error->getStatusCode());
            self::assertStringContainsString('app;dur=', $error->headers->get('Server-Timing'));
            self::assertStringNotContainsString('processor;', $error->headers->get('Server-Timing'));
            $health = $kernel->handle(Request::create('/healthz'));
            self::assertMatchesRegularExpression('/^request;dur=[0-9.]+, app;dur=[0-9.]+$/', $health->headers->get('Server-Timing'));
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
}
