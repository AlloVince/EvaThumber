<?php

declare(strict_types=1);

namespace EvaThumber\Http;

use EvaThumber\Cache\CacheEntry;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Keeps the cache file leased throughout prepare() and sendContent(). */
final class CachedFileResponse extends BinaryFileResponse
{
    private ?CacheEntry $entry;

    /** @param array<string,string> $headers */
    public function __construct(CacheEntry $entry, array $headers)
    {
        $this->entry = $entry;
        parent::__construct($this->entry->path, 200, $headers);
    }

    public function sendContent(): static
    {
        try {
            return parent::sendContent();
        } finally {
            $this->entry = null;
        }
    }
}
