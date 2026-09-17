<?php

declare(strict_types=1);

namespace EvaThumber\Security;

use EvaThumber\Exception\ImageException;

final readonly class Limits
{
    public function __construct(
        public int $maxSourceBytes = 33554432,
        public int $maxSourcePixels = 40000000,
        public int $maxSourceDimension = 20000,
        public int $maxOutputDimension = 4096,
        public int $maxOutputPixels = 16000000,
        public int $maxSteps = 8,
        public int $maxParameters = 64,
        public int $maxUrlLength = 4096,
    ) {
        foreach (get_object_vars($this) as $value) {
            if ($value < 1 || $value > 2147483647) {
                throw new \InvalidArgumentException('Limits must be positive 32-bit integers.');
            }
        }
    }

    public function source(int $width, int $height): void
    {
        $this->dimensions($width, $height, $this->maxSourceDimension, $this->maxSourcePixels);
    }

    public function output(int $width, int $height): void
    {
        $this->dimensions($width, $height, $this->maxOutputDimension, $this->maxOutputPixels);
    }

    private function dimensions(int $width, int $height, int $dimension, int $pixels): void
    {
        if ($width < 1 || $height < 1 || $width > $dimension || $height > $dimension
            || $width > intdiv($pixels, $height)) {
            throw new ImageException('Image dimensions exceed configured limits.', 413, 'image_too_large');
        }
    }
}
