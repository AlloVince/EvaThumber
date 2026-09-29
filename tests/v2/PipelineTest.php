<?php

declare(strict_types=1);

namespace EvaThumber\Tests;

use EvaThumber\Image\Pipeline;
use EvaThumber\Source\SourceImage;
use EvaThumber\Transformation\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    public function testExplicitFormatOverridesDeliveryFormat(): void
    {
        $this->transformFormat('f_webp', 'jpg', 'image/webp');
    }

    public function testNoFormatDefaultsToDeliveryFormat(): void
    {
        $this->transformFormat('c_fill,w_10,h_10', 'webp', 'image/webp');
    }

    public function testInvalidDeliveryChainsRemainRejected(): void
    {
        foreach (['q_80/q_90', 'q_80/q_80', 'f_webp/f_jpg', 'f_webp/f_webp',
            'q_80/w_10', 'f_webp/a_90', 'f_webp/e_grayscale', 'w_10,q_80/w_5'] as $expression) {
            try {
                (new Parser())->parse($expression);
                self::fail('Expected rejection: ' . $expression);
            } catch (\EvaThumber\Exception\ImageException $error) {
                self::assertSame(400, $error->status);
            }
        }
    }

    public function testTrailingDeliveryComponentsAreEquivalent(): void
    {
        $parser = new Parser();
        $expected = $parser->parse('c_fit,w_10/f_webp,q_80')->canonical();
        foreach (['c_fit,w_10/q_80/f_webp', 'c_fit,w_10/f_webp/q_80'] as $expression) {
            self::assertSame($expected, $parser->parse($expression)->canonical());
            $this->transformFormat($expression, 'jpg', 'image/webp');
        }
        $mixed = $parser->parse('c_fit,w_10,q_80/f_webp');
        self::assertSame($parser->parse('c_fit,w_10,q_80,f_webp')->canonical(), $mixed->canonical());
        self::assertSame($mixed->canonical(), $parser->parse($mixed->canonical())->canonical());
        self::assertTrue(array_is_list($mixed->steps));
        $direct = new \EvaThumber\Transformation\Transformation([
            new \EvaThumber\Transformation\Step(['q' => '80']),
            new \EvaThumber\Transformation\Step(['f' => 'webp']),
        ]);
        self::assertSame('f_webp,q_80', $direct->canonical());
    }

    private function transformFormat(string $transformation, string $deliveryFormat, string $expectedMime): void
    {
        $input = tempnam(sys_get_temp_dir(), 'eva-input-');
        $output = tempnam(sys_get_temp_dir(), 'eva-output-');
        self::assertIsString($input);
        self::assertIsString($output);
        try {
            Image::black(20, 10, ['bands' => 3])->pngsave($input);
            $source = new SourceImage($input, 'fixture', time(), (int) filesize($input), 'png');
            (new Pipeline())->write($source, (new Parser())->parse($transformation), $output, $deliveryFormat);
            self::assertSame($expectedMime, (new \finfo(FILEINFO_MIME_TYPE))->file($output));
        } finally {
            unlink($input);
            unlink($output);
        }
    }
    #[DataProvider('unsupportedProvider')]
    public function testUnsupportedCombinations(string $transformation, string $expectedError): void
    {
        try {
            (new Parser())->parse($transformation);
            self::fail('Expected ImageException');
        } catch (\EvaThumber\Exception\ImageException $exception) {
            self::assertSame($expectedError, $exception->error);
        }
    }

    public static function unsupportedProvider(): iterable
    {
        yield 'auto gravity is not claimed equivalent' => ['c_fill,w_100,h_100,g_auto', 'unsupported_transformation'];
        yield 'unknown auto quality rejected' => ['q_auto:unknown', 'unsupported_transformation'];
        yield 'sensitive quality is unsupported' => ['q_auto:good:sensitive', 'unsupported_transformation'];
        yield 'ratio with three parts rejected' => ['c_scale,w_100,ar_3:2:1', 'unsupported_transformation'];
        yield 'coordinate crop requires north west' => ['c_crop,w_100,h_50,g_center,x_10', 'unsupported_transformation'];
    }


    public function testRealFillAndChain(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'eva-input-');
        $output = tempnam(sys_get_temp_dir(), 'eva-output-');
        self::assertIsString($input);
        self::assertIsString($output);
        try {
            Image::black(400, 200, ['bands' => 3])->newFromImage([255, 0, 0])->pngsave($input);
            $source = new SourceImage($input, 'fixture', time(), (int) filesize($input), 'png');
            (new Pipeline())->write($source, (new Parser())->parse('c_fill,w_100,h_100'), $output, 'png');
            $image = Image::newFromFile($output);
            self::assertSame(100, $image->width);
            self::assertSame(100, $image->height);
            self::assertEquals([255, 0, 0], $image->getpoint(50, 50));
            unset($image);
            (new Pipeline())->write($source, (new Parser())->parse('c_fit,w_100,h_100/a_90'), $output, 'png');
            $image = Image::newFromFile($output);
            self::assertSame(50, $image->width);
            self::assertSame(100, $image->height);
        } finally {
            unlink($input);
            unlink($output);
        }
    }

    /**
     * vips_rot and vips_flip are in-place operations. Against a source loaded
     * with sequential access libvips aborts with "out of order read" once the
     * image passes roughly a megapixel, which the pipeline reported as a generic
     * invalid_image. Measured on this fixture the 90 and 270 degree rotations
     * fail; 180, hflip and vflip survive, so their cases are here to catch the
     * same regression reaching them. Every fixture in this suite used to be
     * smaller than the threshold, which is why this went unnoticed.
     */
    #[DataProvider('rotationProvider')]
    public function testRotationAndFlipAboveOneMegapixel(string $transformation, int $width, int $height): void
    {
        $input = tempnam(sys_get_temp_dir(), 'eva-input-');
        $output = tempnam(sys_get_temp_dir(), 'eva-output-');
        self::assertIsString($input);
        self::assertIsString($output);
        try {
            Image::black(1200, 800, ['bands' => 3])->newFromImage([10, 120, 200])->pngsave($input);
            $source = new SourceImage($input, 'fixture', time(), (int) filesize($input), 'png');
            (new Pipeline())->write($source, (new Parser())->parse($transformation), $output, 'png');
            $image = Image::newFromFile($output);
            self::assertSame($width, $image->width, $transformation);
            self::assertSame($height, $image->height, $transformation);
        } finally {
            unlink($input);
            unlink($output);
        }
    }

    public static function rotationProvider(): iterable
    {
        yield 'rotate 90 clockwise' => ['a_90', 800, 1200];
        yield 'rotate 180' => ['a_180', 1200, 800];
        yield 'rotate 270 clockwise' => ['a_270', 800, 1200];
        yield 'rotate 90 anticlockwise' => ['a_-90', 800, 1200];
        yield 'flip horizontally' => ['a_hflip', 1200, 800];
        yield 'flip vertically' => ['a_vflip', 1200, 800];
    }

    /**
     * The canonical string is the only channel by which ar reaches the
     * transform subprocess, so it has to survive a float round trip. Truncating
     * it to 14 significant digits turned w_600,ar_16:9 into 600x337.
     */
    #[DataProvider('aspectRatioProvider')]
    public function testAspectRatioSurvivesCanonicalisation(string $transformation, int $width, int $height): void
    {
        $input = tempnam(sys_get_temp_dir(), 'eva-input-');
        $output = tempnam(sys_get_temp_dir(), 'eva-output-');
        self::assertIsString($input);
        self::assertIsString($output);
        try {
            Image::black(1200, 800, ['bands' => 3])->newFromImage([10, 120, 200])->pngsave($input);
            $source = new SourceImage($input, 'fixture', time(), (int) filesize($input), 'png');
            $parser = new Parser();
            // The subprocess only ever receives the canonical form, so assert
            // against that exact string rather than the expression typed above.
            $canonical = $parser->parse($transformation)->canonical();
            self::assertSame($canonical, $parser->parse($canonical)->canonical());
            (new Pipeline())->write($source, $parser->parse($canonical), $output, 'png');
            $image = Image::newFromFile($output);
            self::assertSame($width, $image->width, $transformation);
            self::assertSame($height, $image->height, $transformation);
        } finally {
            unlink($input);
            unlink($output);
        }
    }

    public static function aspectRatioProvider(): iterable
    {
        yield 'sixteen by nine at the half pixel boundary' => ['c_fill,w_600,ar_16:9', 600, 338];
        yield 'sixteen by nine at a whole pixel' => ['c_fill,w_1600,ar_16:9', 1600, 900];
        yield 'four by three' => ['c_fill,w_600,ar_4:3', 600, 450];
        yield 'nine by sixteen' => ['c_fill,w_1080,ar_9:16', 1080, 1920];
        yield 'twenty one by nine' => ['c_fill,w_1000,ar_21:9', 1000, 429];
        yield 'three by two' => ['c_fill,w_1000,ar_3:2', 1000, 667];
        yield 'square' => ['c_fill,w_600,ar_1:1', 600, 600];
    }
}
