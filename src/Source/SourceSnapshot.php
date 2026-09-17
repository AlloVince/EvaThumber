<?php

declare(strict_types=1);

namespace EvaThumber\Source;

use EvaThumber\Exception\ImageException;

/** An immutable, bounded copy of encoded bytes, not a decoded pixel buffer. */
final readonly class SourceSnapshot
{
    /** @param array<string, int> $stat */
    private function __construct(
        public string $content,
        public string $digest,
        public array $stat,
    ) {
    }

    public static function read(string $path, int $maxBytes): self
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new ImageException('Image not found.', 404, 'source_unavailable');
        }
        try {
            $before = fstat($stream);
            if ($before === false || ($before['mode'] & 0170000) !== 0100000) {
                throw new ImageException('Image not found.', 404, 'source_unavailable');
            }
            if ($before['size'] > $maxBytes) {
                throw new ImageException('Source exceeds byte limit.', 413, 'image_too_large');
            }
            // Do not trust stat to bound a concurrent grow. Read at most limit + 1.
            $content = stream_get_contents($stream, $maxBytes + 1);
            if ($content === false) {
                throw new ImageException('Source could not be read.', 409, 'source_changed');
            }
            if (strlen($content) > $maxBytes) {
                throw new ImageException('Source exceeds byte limit.', 413, 'image_too_large');
            }
            $after = fstat($stream);
            clearstatcache(true, $path);
            $current = @stat($path);
            // atime may change because of our own read. Never include it.
            $fields = array_flip(['dev', 'ino', 'mtime', 'ctime', 'size']);
            $revision = array_intersect_key($before, $fields);
            if ($after === false || $current === false || strlen($content) !== $before['size']
                || array_intersect_key($after, $fields) !== $revision
                || array_intersect_key($current, $fields) !== $revision) {
                throw new ImageException('Source changed during resolution.', 409, 'source_changed');
            }
            // Even an undetectable in-place ABA during read cannot detach this
            // digest from decoded bytes: both are derived from this exact copy.
            return new self($content, hash('sha256', $content), $revision);
        } finally {
            fclose($stream);
        }
    }
}
