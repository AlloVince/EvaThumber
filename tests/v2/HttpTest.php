<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Http\Kernel;
use EvaThumber\Http\Settings;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HttpTest extends TestCase
{
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
}
