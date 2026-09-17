<?php

declare(strict_types=1);

namespace EvaThumber\Cache;

use EvaThumber\Exception\ImageException;

final readonly class DiskCache
{
    public function __construct(private string $root, private int $maxBytes = 1073741824, private int $maxEntries = 10000)
    {
        if ($maxBytes < 1 || $maxEntries < 1) {
            throw new \InvalidArgumentException('Cache limits must be positive.');
        }
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
            throw new \RuntimeException('Cannot initialize cache.');
        }
    }

    /** @param callable(string):void $producer */
    public function remember(string $identity, string $format, callable $producer): CacheEntry
    {
        if (!in_array($format, ['jpg', 'png', 'webp', 'avif', 'gif'], true)) {
            throw new \InvalidArgumentException('Invalid cache format.');
        }
        $key = hash('sha256', $identity . ':' . $format);
        $path = $this->root . '/' . $key . '.' . $format;
        if (is_file($path)) {
            return $this->entry($path, $key, true);
        }
        // A nonblocking global admission lock bounds concurrent native work and temp files.
        // Hits do not acquire it. Busy misses return Retry-After instead of accumulating workers.
        $lock = fopen($this->root . '/.publish.lock', 'c');
        if ($lock === false) {
            throw new ImageException('Cache unavailable.', 503, 'cache_unavailable');
        }
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new ImageException('Image processor busy. Retry shortly.', 503, 'processor_busy');
            }
            if (is_file($path)) {
                return $this->entry($path, $key, true);
            }
            $bytes = 0;
            $entries = 0;
            foreach (new \DirectoryIterator($this->root) as $file) {
                if ($file->isFile() && preg_match('/\A[0-9a-f]{64}\.(jpg|png|webp|avif|gif)\z/', $file->getFilename())) {
                    $bytes += $file->getSize();
                    ++$entries;
                } elseif ($file->isFile() && str_starts_with($file->getFilename(), '.tmp-')) {
                    // Any old temporary file is abandoned: all producers hold this lock.
                    unlink($file->getPathname());
                }
            }
            if ($entries >= $this->maxEntries || $bytes >= $this->maxBytes) {
                throw new ImageException('Cache capacity reached.', 507, 'cache_full');
            }
            $temporary = tempnam($this->root, '.tmp-');
            if ($temporary === false) {
                $temporary = null;
                throw new ImageException('Cache unavailable.', 503, 'cache_unavailable');
            }
            $producer($temporary);
            clearstatcache(true, $temporary);
            $size = filesize($temporary);
            if ($size === false || $size < 1 || $size > $this->maxBytes - $bytes) {
                throw new ImageException('Derived image exceeds cache capacity.', 507, 'cache_full');
            }
            if (!rename($temporary, $path)) {
                throw new ImageException('Cache publication failed.', 503, 'cache_unavailable');
            }
            $temporary = null;
            return $this->entry($path, $key, false);
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function entry(string $path, string $key, bool $hit): CacheEntry
    {
        $modifiedAt = filemtime($path);
        if ($modifiedAt === false) {
            throw new ImageException('Cache entry unavailable.', 503, 'cache_unavailable');
        }
        return new CacheEntry($path, $key, $modifiedAt, $hit);
    }
}
