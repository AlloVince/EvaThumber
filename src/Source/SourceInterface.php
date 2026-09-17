<?php

declare(strict_types=1);

namespace EvaThumber\Source;

interface SourceInterface
{
    public function resolve(string $publicId): SourceImage;
}
