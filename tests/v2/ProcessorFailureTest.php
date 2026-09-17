<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Http\Kernel;
use EvaThumber\Http\Settings;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ProcessorFailureTest extends TestCase
{
    public function testTimeoutCleansTemporaryAndAllowsNextJob(): void
    {
        $this->assertFailureRecovers('usleep(10_000_000);', 504, 'processing_timeout');
    }

    public function testUnexpectedChildExitDoesNotPublishPartialBytes(): void
    {
        $this->assertFailureRecovers('fwrite(STDERR, "test child failure"); exit(7);', 422, 'invalid_image');
    }

    public function testStructuredChildRejectionIsPreserved(): void
    {
        $this->assertFailureRecovers('echo json_encode(["status" => 413, "error" => "image_too_large"]); exit(1);', 413, 'image_too_large');
    }

    private function assertFailureRecovers(string $childCode, int $status, string $error): void
    {
        $root = sys_get_temp_dir() . '/eva-processor-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/images');
        mkdir($root . '/cache');
        // Trusted test executable ignores CLI arguments but exercises real Process lifecycle and IPC.
        $executable = $root . '/test-php';
        file_put_contents($executable, '#!' . PHP_BINARY . "\n<?php\n"
            . '$job = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);'
            . 'file_put_contents($job["destination"], "partial");'
            . 'file_put_contents(dirname($job["root"]) . "/child.pid", (string) getmypid());'
            . $childCode);
        chmod($executable, 0700);
        try {
            Image::black(20, 10, ['bands' => 3])->pngsave($root . '/images/demo.png');
            $kernel = new Kernel(new Settings($root . '/images', $root . '/cache', timeout: 1, phpBinary: $executable));
            $start = hrtime(true);
            $failure = $kernel->handle(Request::create('/image/upload/w_10/demo.png'));
            self::assertSame($status, $failure->getStatusCode());
            self::assertSame($error, json_decode((string) $failure->getContent(), true)['error']);
            self::assertTrue($failure->headers->hasCacheControlDirective('no-store'));
            self::assertSame([], glob($root . '/cache/.tmp-*'));
            self::assertSame([], glob($root . '/cache/*.png'));
            self::assertFileExists($root . '/child.pid');
            if ($status === 504) {
                self::assertGreaterThanOrEqual(1.0, (hrtime(true) - $start) / 1e9);
                self::assertLessThan(5.0, (hrtime(true) - $start) / 1e9);
            }
            $normal = new Kernel(new Settings($root . '/images', $root . '/cache'));
            $response = $normal->handle(Request::create('/image/upload/w_10/demo.png'));
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame('MISS', $response->headers->get('X-EvaThumber-Cache'));
            self::assertCount(1, glob($root . '/cache/*.png'));
            self::assertSame([], glob($root . '/cache/.tmp-*'));
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
            foreach (glob($root . '/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
}
