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
        $entry = $this->entry($path, $key, true);
        if ($entry !== null) {
            return $entry;
        }
        // Eight admitted misses per root (including the single producer).
        // Hits bypass admission; excess misses fail without entering the wait loop.
        $admission = $this->admit();
        $deadline = hrtime(true) + 250_000_000;
        $lock = null;
        $temporary = null;
        try {
            $lock = fopen($this->root . '/.publish.lock', 'c');
            if ($lock === false) {
                throw new ImageException('Cache unavailable.', 503, 'cache_unavailable');
            }
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                $entry = $this->entry($path, $key, true);
                if ($entry !== null) {
                    return $entry;
                }
                $remaining = $deadline - hrtime(true);
                if ($remaining <= 0) {
                    throw new ImageException('Image processor busy. Retry shortly.', 503, 'processor_busy');
                }
                usleep((int) min(10_000, max(1, intdiv($remaining, 1000))));
            }
            $entry = $this->entry($path, $key, true);
            if ($entry !== null) {
                return $entry;
            }
            foreach (new \DirectoryIterator($this->root) as $file) {
                if ($file->isFile() && str_starts_with($file->getFilename(), '.tmp-')) {
                    // All producers still hold the global admission lock.
                    unlink($file->getPathname());
                }
            }
            $temporary = tempnam($this->root, '.tmp-');
            if ($temporary === false) {
                $temporary = null;
                throw new ImageException('Cache unavailable.', 503, 'cache_unavailable');
            }
            $producer($temporary);
            clearstatcache(true, $temporary);
            $size = filesize($temporary);
            if ($size === false || $size < 1 || $size > $this->maxBytes) {
                throw new ImageException('Derived image exceeds cache capacity.', 507, 'cache_full');
            }
            $this->makeRoom($size);
            if (!rename($temporary, $path)) {
                throw new ImageException('Cache publication failed.', 503, 'cache_unavailable');
            }
            $temporary = null;
            return $this->entry($path, $key, false) ?? throw new ImageException('Cache entry unavailable.', 503, 'cache_unavailable');
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            fclose($admission);
        }
    }

    /** @return resource */
    private function admit()
    {
        // Stable inodes: never unlink these files while any instance is running.
        // Kernel-released flock leases need no stale-PID registry or cleanup job.
        for ($slot = 0; $slot < 8; ++$slot) {
            $handle = fopen($this->root . '/.admission-' . $slot . '.lock', 'c');
            if ($handle === false) {
                throw new ImageException('Cache unavailable.', 503, 'cache_unavailable');
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            fclose($handle);
        }
        throw new ImageException('Image processor busy. Retry shortly.', 503, 'processor_busy');
    }

    private function makeRoom(int $incomingBytes): void
    {
        $bytes = 0;
        $files = [];
        foreach (new \DirectoryIterator($this->root) as $file) {
            if ($file->isFile() && !$file->isLink() && preg_match('/\A[0-9a-f]{64}\.(jpg|png|webp|avif|gif)\z/', $file->getFilename())) {
                $size = $file->getSize();
                $bytes += $size;
                $files[] = ['path' => $file->getPathname(), 'size' => $size, 'time' => $file->getMTime()];
            }
        }
        $entries = count($files);
        usort($files, static fn (array $a, array $b): int => [$a['time'], $a['path']] <=> [$b['time'], $b['path']]);
        foreach ($files as $file) {
            if ($bytes + $incomingBytes <= $this->maxBytes && $entries < $this->maxEntries) {
                return;
            }
            $handle = @fopen($file['path'], 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                // A response keeps a shared lease until it is sent or destroyed.
                if (flock($handle, LOCK_EX | LOCK_NB) && unlink($file['path'])) {
                    $bytes -= $file['size'];
                    --$entries;
                }
            } finally {
                fclose($handle);
            }
        }
        if ($bytes + $incomingBytes > $this->maxBytes || $entries >= $this->maxEntries) {
            throw new ImageException('Cache entries are in use. Retry shortly.', 503, 'cache_busy');
        }
    }

    private function entry(string $path, string $key, bool $hit): ?CacheEntry
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        $stat = fstat($handle);
        clearstatcache(true, $path);
        $current = @stat($path);
        if ($stat === false || $current === false || $stat['ino'] !== $current['ino'] || $stat['size'] < 1) {
            fclose($handle);
            return null;
        }
        return new CacheEntry($path, $key, $stat['mtime'], $hit, $handle);
    }
}
