<?php

declare(strict_types=1);

namespace EvaThumber\Source;

final readonly class SourceImage
{
    public function __construct(
        public string $path,
        public string $identity,
        public int $modifiedAt,
        public int $bytes,
        public string $format,
        public ?SourceSnapshot $snapshot = null,
    ) {
    }
}
