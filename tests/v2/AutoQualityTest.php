<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Image\AutoQuality;
use EvaThumber\Transformation\Parser;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\TestCase;

final class AutoQualityTest extends TestCase
{
    public function testQualityAdaptsToContentAndOrdersTiers(): void
    {
        $flat = Image::black(64, 64, ['bands' => 3])->newFromImage([128, 128, 128]);
        $edges = Image::xyz(64, 64)->extract_band(0)->remainder(2)->multiply(255);
        $edges = $edges->bandjoin([$edges, $edges])->cast('uchar');
        $selector = new AutoQuality();
        self::assertGreaterThan($selector->select($flat, 'auto:good'), $selector->select($edges, 'auto:good'));
        foreach ([$flat, $edges] as $image) {
            $values = [];
            foreach (['auto:best', 'auto:good', 'auto:eco', 'auto:low'] as $tier) {
                $quality = $selector->select($image, $tier);
                self::assertGreaterThanOrEqual(1, $quality);
                self::assertLessThanOrEqual(100, $quality);
                $values[] = $quality;
            }
            $sorted = $values;
            rsort($sorted);
            self::assertSame($sorted, $values);
            self::assertSame($selector->select($image, 'auto:good'), $selector->select($image, 'auto'));
        }
        self::assertSame('q_auto:good', (new Parser())->parse('q_auto')->canonical());
    }

    public function testTinyAndTransparentSamplesRemainDefined(): void
    {
        $selector = new AutoQuality();
        foreach ([[1, 1], [1, 50], [50, 1], [512, 512]] as [$width, $height]) {
            $image = Image::black($width, $height, ['bands' => 3]);
            self::assertSame(72, $selector->select($image, 'auto:good'));
        }
        $hidden = Image::xyz(64, 64)->extract_band(0)->multiply(4);
        $hidden = $hidden->bandjoin([$hidden, $hidden, 0])->cast('uchar');
        $white = Image::black(64, 64, ['bands' => 3])->newFromImage([255, 255, 255]);
        self::assertSame($selector->select($white, 'auto'), $selector->select($hidden, 'auto'));
        self::assertSame(68, $selector->select($white, 'auto', 'webp'));
        self::assertSame(48, $selector->select($white, 'auto', 'avif'));
    }

    public function testPipelineEncodesSelectedQualityAndPreservesAlpha(): void
    {
        $root = sys_get_temp_dir() . '/eva-quality-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            $xy = Image::xyz(320, 240);
            $x = $xy->extract_band(0);
            $y = $xy->extract_band(1);
            $x->bandjoin([$y, $x->add($y)])->cast('uchar')->pngsave($root . '/source.png');
            $source = (new \EvaThumber\Source\LocalSource($root))->resolve('source');
            $pipeline = new \EvaThumber\Image\Pipeline();
            $parser = new Parser();
            foreach (['jpg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif'] as $format => $mime) {
                foreach (['best', 'good', 'eco', 'low'] as $tier) {
                    $quality = (new AutoQuality())->select(Image::pngload($source->path)->resize(0.5), 'auto:' . $tier, $format);
                    $pipeline->write($source, $parser->parse('w_160/q_auto:' . $tier), $root . '/auto', $format);
                    $pipeline->write($source, $parser->parse('w_160/q_' . $quality), $root . '/numeric', $format);
                    self::assertSame(hash_file('sha256', $root . '/numeric'), hash_file('sha256', $root . '/auto'), $format . ':' . $tier);
                    self::assertSame($mime, (new \finfo(FILEINFO_MIME_TYPE))->file($root . '/auto'));
                    $decoded = Image::newFromFile($root . '/auto');
                    self::assertSame(160, $decoded->width);
                    self::assertSame(120, $decoded->height);
                    unset($decoded);
                }
            }
            foreach (['png', 'gif'] as $format) {
                try {
                    $pipeline->write($source, $parser->parse('q_auto'), $root . '/rejected', $format);
                    self::fail('Unsupported automatic quality must not be ignored.');
                } catch (\EvaThumber\Exception\ImageException $error) {
                    self::assertSame('unsupported_transformation', $error->error);
                    self::assertSame(400, $error->status);
                    self::assertFileDoesNotExist($root . '/rejected');
                }
            }
            Image::black(40, 20, ['bands' => 3])->newFromImage([255, 0, 0])->bandjoin(128)->pngsave($root . '/alpha.png');
            $alpha = (new \EvaThumber\Source\LocalSource($root))->resolve('alpha');
            $pipeline->write($alpha, $parser->parse('q_auto/f_webp'), $root . '/alpha.webp', 'jpg');
            $decoded = Image::webpload($root . '/alpha.webp');
            self::assertTrue($decoded->hasAlpha());
            self::assertEquals(128, $decoded->getpoint(10, 10)[3]);
            unset($decoded);
        } finally {
            foreach (new \DirectoryIterator($root) as $file) {
                if (!$file->isDot()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($root);
        }
    }

    public function testHttpDefaultTierSharesCacheAndOtherTiersDoNot(): void
    {
        $root = sys_get_temp_dir() . '/eva-quality-http-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . '/cache');
        try {
            Image::black(40, 20, ['bands' => 3])->pngsave($root . '/demo.png');
            $kernel = new \EvaThumber\Http\Kernel(new \EvaThumber\Http\Settings($root, $root . '/cache'));
            $etag = null;
            foreach (['q_auto/f_auto', 'f_auto/q_auto:good', 'q_auto:eco/f_auto'] as $index => $expression) {
                $request = \Symfony\Component\HttpFoundation\Request::create('/image/upload/' . $expression . '/demo.jpg');
                $request->headers->set('Accept', 'image/webp');
                $response = $kernel->handle($request);
                self::assertSame(200, $response->getStatusCode());
                self::assertSame('image/webp', $response->headers->get('Content-Type'));
                self::assertSame('Accept', $response->headers->get('Vary'));
                self::assertSame($index === 1 ? 'HIT' : 'MISS', $response->headers->get('X-EvaThumber-Cache'));
                if ($index === 0) {
                    $etag = $response->headers->get('ETag');
                } elseif ($index === 1) {
                    self::assertSame($etag, $response->headers->get('ETag'));
                } else {
                    self::assertNotSame($etag, $response->headers->get('ETag'));
                }
            }
            $rejected = $kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/image/upload/q_auto/demo.png'));
            self::assertSame(400, $rejected->getStatusCode());
        } finally {
            unset($response);
            foreach (new \DirectoryIterator($root . '/cache') as $file) {
                if (!$file->isDot()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($root . '/cache');
            unlink($root . '/demo.png');
            rmdir($root);
        }
    }
}
