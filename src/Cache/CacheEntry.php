<?php

declare(strict_types=1);

namespace EvaThumber\Cache;

final readonly class CacheEntry
{
    public function __construct(public string $path, public string $etag, public int $modifiedAt, public bool $hit)
    {
    }
}
