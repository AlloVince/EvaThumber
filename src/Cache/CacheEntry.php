<?php

declare(strict_types=1);

namespace EvaThumber\Cache;

/**
 * Holds a shared lease on the entry file until the response is sent or the
 * object is destroyed, so eviction cannot remove a file mid-delivery.
 */
final class CacheEntry
{
    /** @var resource|null */
    private $handle;

    /** @param resource|null $handle */
    public function __construct(public string $path, public string $etag, public int $modifiedAt, public bool $hit, $handle = null)
    {
        $this->handle = $handle;
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
