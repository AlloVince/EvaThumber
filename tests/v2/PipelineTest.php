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
        yield 'auto quality is not faked' => ['q_auto', 'unsupported_transformation'];
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
}
