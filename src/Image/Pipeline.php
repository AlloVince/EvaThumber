<?php

declare(strict_types=1);

namespace EvaThumber\Image;

use EvaThumber\Exception\ImageException;
use EvaThumber\Security\Limits;
use EvaThumber\Source\SourceImage;
use EvaThumber\Transformation\Step;
use EvaThumber\Transformation\Transformation;
use Jcupitt\Vips\Config;
use Jcupitt\Vips\Image;

final readonly class Pipeline
{
    public function __construct(private Limits $limits = new Limits())
    {
    }

    public function write(SourceImage $source, Transformation $transform, string $destination, string $format): void
    {
        // Revalidate models constructed directly by library callers.
        $transform = (new \EvaThumber\Transformation\Parser($this->limits))->parse($transform->canonical());
        if ($source->bytes > $this->limits->maxSourceBytes) {
            throw new ImageException('Source exceeds byte limit.', 413, 'image_too_large');
        }
        $requestedFormat = $transform->get('f');
        if ($requestedFormat !== null && $requestedFormat !== 'auto') {
            $format = $requestedFormat;
        }
        $loaders = ['jpg' => 'jpegload', 'png' => 'pngload', 'webp' => 'webpload', 'avif' => 'heifload', 'gif' => 'gifload'];
        if (!isset($loaders[$source->format], $loaders[$format])) {
            throw new ImageException('Unsupported image format.', 415, 'unsupported_format');
        }
        // LocalSource supplies identity-bound bytes. Legacy manually constructed
        // models are copied at entry too, but their caller-defined identity is opaque.
        $snapshot = $source->snapshot ?? \EvaThumber\Source\SourceSnapshot::read($source->path, $this->limits->maxSourceBytes);
        if (strlen($snapshot->content) > $this->limits->maxSourceBytes) {
            throw new ImageException('Source exceeds byte limit.', 413, 'image_too_large');
        }
        Config::cacheSetMax(0);
        Config::concurrencySet(2);
        try {
            $image = $this->prepare($snapshot->content, $loaders[$source->format] . '_buffer', $transform, $format);
            $qualityValue = $transform->get('q') ?? '80';
            if (str_starts_with($qualityValue, 'auto')) {
                if (!in_array($format, ['jpg', 'webp', 'avif'], true)) {
                    throw new ImageException('Automatic quality requires JPEG, WebP or AVIF output.', 400, 'unsupported_transformation');
                }
                // Consume the lazy graph only for the bounded analysis sample.
                // Rebuild from identical encoded bytes, never rewind a sequential graph.
                $quality = (new AutoQuality())->select($image, $qualityValue, $format);
                unset($image);
                $image = $this->prepare($snapshot->content, $loaders[$source->format] . '_buffer', $transform, $format);
            } else {
                $quality = (int) $qualityValue;
            }
            match ($format) {
                'jpg' => $image->jpegsave($destination, ['Q' => $quality, 'strip' => true]),
                'png' => $image->pngsave($destination, ['strip' => true]),
                'webp' => $image->webpsave($destination, ['Q' => $quality, 'strip' => true]),
                'avif' => $image->heifsave($destination, ['Q' => $quality, 'compression' => 'av1', 'effort' => 3, 'strip' => true]),
                'gif' => $image->gifsave($destination, ['strip' => true]),
            };
        } catch (\Jcupitt\Vips\Exception $exception) {
            throw new ImageException('Image could not be decoded or transformed.', 422, 'invalid_image');
        }
    }

    /** Build a fresh graph; callers may evaluate it once. */
    private function prepare(string $content, string $loader, Transformation $transform, string $format): Image
    {
        // Explicit raster buffer loader: no filename dispatch or mutable path reads.
        // Random access, not sequential: vips_rot and vips_flip are in-place
        // operations, and libvips aborts with "out of order read" when they run
        // against a sequential source above roughly a megapixel. A copy() does
        // not help -- the loader contract is what breaks.
        //
        // Peak RSS here is set by the source encoding, not by this choice: on the
        // 17.9 MPix demo.jpg one w_600 job peaks at 167 MiB because it is a
        // progressive JPEG, against 67 MiB for the same pixels stored as a
        // baseline JPEG, and sequential access is no cheaper there (179 MiB).
        // q_auto builds the graph twice and peaks at 178 MiB, against the 192 MiB
        // WORKER_RSS_MIB default, which the pool enforces by recycling idle
        // workers rather than by killing a running one.
        $image = Image::{$loader}($content, ['access' => 'random', 'fail_on' => 'warning']);
        $this->limits->source($image->width, $image->height);
        if ($image->getType('n-pages') !== 0 && $image->get('n-pages') > 1) {
            throw new ImageException('Animated images are not yet supported.', 415, 'unsupported_animation');
        }
        $image = $image->autorot()->colourspace('srgb');
        foreach ($transform->steps as $step) {
            if ($step->get('c') !== null) {
                $image = $this->resize($image, $step);
            }
            if (($angle = $step->get('a')) !== null) {
                $image = match ($angle) {
                    '90' => $image->rot('d90'), '180' => $image->rot('d180'),
                    '270', '-90' => $image->rot('d270'),
                    'hflip' => $image->flip('horizontal'), 'vflip' => $image->flip('vertical'),
                    default => $image,
                };
            }
            if (($effect = $step->get('e')) !== null) {
                $alpha = $image->hasAlpha() ? $image->extract_band($image->bands - 1) : null;
                $rgb = $alpha !== null ? $image->extract_band(0, ['n' => $image->bands - 1]) : $image;
                $rgb = $effect === 'grayscale' ? $rgb->colourspace('b-w')->colourspace('srgb') : $rgb->invert();
                $image = $alpha !== null ? $rgb->bandjoin($alpha) : $rgb;
            }
            $this->limits->output($image->width, $image->height);
        }
        $this->limits->output($image->width, $image->height);
        return $format === 'jpg' && $image->hasAlpha()
            ? $image->flatten(['background' => [255, 255, 255]]) : $image;
    }

    private function resize(Image $image, Step $step): Image
    {
        $iw = $image->width;
        $ih = $image->height;
        $w = $this->dimension($step->get('w'), $iw);
        $h = $this->dimension($step->get('h'), $ih);
        $ratio = (float) $step->get('ar', (string) ($iw / $ih));
        $w ??= max(1, (int) round(($h ?? $ih) * $ratio));
        $h ??= max(1, (int) round($w / $ratio));
        $dpr = (float) $step->get('dpr', '1');
        $w = max(1, (int) round($w * $dpr));
        $h = max(1, (int) round($h * $dpr));
        $this->limits->output($w, $h);
        $mode = $step->get('c', 'scale');
        if ($mode === 'crop') {
            $w = min($w, $iw);
            $h = min($h, $ih);
            [$x, $y] = $this->position($iw - $w, $ih - $h, $step);
            $x += (int) $step->get('x', '0');
            $y += (int) $step->get('y', '0');
            if ($x + $w > $iw || $y + $h > $ih) {
                throw new ImageException('Crop exceeds source boundaries.');
            }
            return $image->crop($x, $y, $w, $h);
        }
        if ($mode === 'scale') {
            return $image->resize($w / $iw, ['vscale' => $h / $ih]);
        }
        $factor = in_array($mode, ['fill', 'thumb'], true) ? max($w / $iw, $h / $ih) : min($w / $iw, $h / $ih);
        if ($mode === 'limit') {
            $factor = min(1, $factor);
        }
        $rw = max(1, (int) round($iw * $factor));
        $rh = max(1, (int) round($ih * $factor));
        $this->limits->output($rw, $rh);
        $image = $image->resize($factor);
        if (in_array($mode, ['fill', 'thumb'], true)) {
            [$x, $y] = $this->position($image->width - $w, $image->height - $h, $step);
            return $image->crop($x, $y, $w, $h);
        }
        if ($mode === 'pad') {
            [$x, $y] = $this->position($w - $image->width, $h - $image->height, $step);
            $background = $step->get('b', 'white');
            if ($background === 'transparent') {
                $image = $image->hasAlpha() ? $image : $image->bandjoin(255);
                $color = [0, 0, 0, 0];
            } else {
                $named = ['white' => 'ffffff', 'black' => '000000', 'red' => 'ff0000', 'green' => '008000', 'blue' => '0000ff'];
                $hex = $named[$background] ?? substr($background, 4);
                $color = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
                if ($image->hasAlpha()) {
                    $image = $image->flatten(['background' => $color]);
                }
            }
            return $image->embed($x, $y, $w, $h, ['extend' => 'background', 'background' => $color]);
        }
        return $image;
    }

    private function dimension(?string $value, int $original): ?int
    {
        if ($value === null) {
            return null;
        }
        $number = (float) $value;
        return max(1, (int) round($number < 1 ? $original * $number : $number));
    }

    /** @return array{int,int} */
    private function position(int $dx, int $dy, Step $step): array
    {
        $g = $step->get('g', 'center');
        $x = str_contains($g, 'west') ? 0 : (str_contains($g, 'east') ? $dx : intdiv($dx, 2));
        $y = str_contains($g, 'north') ? 0 : (str_contains($g, 'south') ? $dy : intdiv($dy, 2));
        return [$x, $y];
    }
}
