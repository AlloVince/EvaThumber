<?php

declare(strict_types=1);

namespace EvaThumber\Image;

use EvaThumber\Exception\ImageException;
use Jcupitt\Vips\Image;

/** Local edge-density heuristic; not Cloudinary's perceptual quality algorithm. */
final class AutoQuality
{
    public const POLICY = 'edge-density-v1';

    public function select(Image $image, string $tier, string $format = 'jpg'): int
    {
        $offset = match ($tier) {
            'auto:best' => 10,
            'auto', 'auto:good' => 0,
            'auto:eco' => -12,
            'auto:low' => -25,
            default => throw new ImageException('Unsupported automatic quality tier.', 400, 'unsupported_transformation'),
        };
        $base = match ($format) {
            'jpg' => 72,
            'webp' => 68,
            'avif' => 48,
            default => throw new ImageException('Automatic quality requires JPEG, WebP or AVIF output.', 400, 'unsupported_transformation'),
        };
        // Bound analysis to 256x256. Materialize only the sample for repeated statistics.
        $scale = min(1.0, 256 / max($image->width, $image->height));
        $sample = $scale < 1 ? $image->resize($scale) : $image;
        $sample = $sample->colourspace('srgb');
        if ($sample->hasAlpha()) {
            // Ignore invisible RGB; use the same white matte as JPEG delivery.
            $sample = $sample->flatten(['background' => [255, 255, 255]]);
        }
        $sample = $sample->cast('uchar')->copyMemory();
        $difference = 0.0;
        $axes = 0;
        if ($sample->width > 1) {
            $difference += $sample->crop(1, 0, $sample->width - 1, $sample->height)
                ->subtract($sample->crop(0, 0, $sample->width - 1, $sample->height))->abs()->avg();
            ++$axes;
        }
        if ($sample->height > 1) {
            $difference += $sample->crop(0, 1, $sample->width, $sample->height - 1)
                ->subtract($sample->crop(0, 0, $sample->width, $sample->height - 1))->abs()->avg();
            ++$axes;
        }
        $detail = min(1.0, $difference / max(1, $axes) / 32);
        return max(1, min(95, $base + (int) round(12 * $detail) + $offset));
    }
}
