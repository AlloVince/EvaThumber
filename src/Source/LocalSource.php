<?php

declare(strict_types=1);

namespace EvaThumber\Source;

use EvaThumber\Exception\ImageException;
use EvaThumber\Security\Limits;

final readonly class LocalSource implements SourceInterface
{
    private string $root;
    private const FORMATS = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif', 'gif' => 'image/gif'];

    public function __construct(string $root, private Limits $limits = new Limits())
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Source directory does not exist.');
        }
        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function assertIdentity(string $publicId, string $identity): SourceImage
    {
        try {
            $source = $this->resolve($publicId);
        } catch (ImageException) {
            throw new ImageException('Source changed during processing.', 409, 'source_changed');
        }
        if (!hash_equals($identity, $source->identity)) {
            throw new ImageException('Source changed during processing.', 409, 'source_changed');
        }
        return $source;
    }

    public function resolve(string $publicId): SourceImage
    {
        clearstatcache(true);
        if ($publicId === '' || str_contains($publicId, '\\') || preg_match('/[\x00-\x1f\x7f%:]/', $publicId)) {
            throw new ImageException('Invalid public ID.');
        }
        foreach (explode('/', $publicId) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                throw new ImageException('Invalid public ID.');
            }
        }
        $candidates = [];
        foreach (array_merge([''], array_map(static fn (string $ext): string => '.' . $ext, array_keys(self::FORMATS))) as $suffix) {
            $path = realpath($this->root . $publicId . $suffix);
            if ($path !== false && is_file($path) && str_starts_with($path, $this->root)) {
                $candidates[$path] = true;
            }
        }
        if (count($candidates) !== 1) {
            throw new ImageException(count($candidates) === 0 ? 'Image not found.' : 'Ambiguous public ID.', count($candidates) === 0 ? 404 : 409, 'source_unavailable');
        }
        $path = array_key_first($candidates);
        $snapshot = SourceSnapshot::read($path, $this->limits->maxSourceBytes);
        $stat = $snapshot->stat;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($snapshot->content);
        $format = array_search($mime, self::FORMATS, true);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($format === false || ($extension !== '' && (self::FORMATS[$extension] ?? null) !== $mime)) {
            throw new ImageException('Unsupported or mismatched source format.', 415, 'unsupported_format');
        }
        return new SourceImage($path, hash('sha256', $path . ':' . $stat['dev'] . ':' . $stat['ino'] . ':' . $stat['mtime'] . ':' . $stat['ctime'] . ':' . $stat['size'] . ':' . $snapshot->digest), $stat['mtime'], strlen($snapshot->content), $format, $snapshot);
    }
}
