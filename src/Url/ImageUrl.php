<?php

declare(strict_types=1);

namespace EvaThumber\Url;

use EvaThumber\Transformation\Transformation;

final readonly class ImageUrl
{
    public function __construct(
        public string $publicId,
        public ?string $format,
        public Transformation $transformation,
        public ?string $cloudName = null,
        public ?string $version = null,
    ) {
    }
}
