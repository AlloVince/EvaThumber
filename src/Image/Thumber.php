<?php

declare(strict_types=1);

namespace EvaThumber\Image;

use EvaThumber\Source\SourceImage;
use EvaThumber\Transformation\Transformation;

final readonly class Thumber
{
    public function __construct(private Pipeline $pipeline = new Pipeline())
    {
    }

    public function transform(SourceImage $source, Transformation $transformation, string $destination, ?string $format = null): void
    {
        $this->pipeline->write($source, $transformation, $destination, $format ?? $source->format);
    }
}
