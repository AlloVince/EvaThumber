<?php

declare(strict_types=1);

namespace EvaThumber\Url;

use EvaThumber\Exception\ImageException;
use EvaThumber\Security\Limits;
use EvaThumber\Transformation\Parser as TransformationParser;

final readonly class Parser
{
    public function __construct(private Limits $limits = new Limits())
    {
    }

    public function parse(string $path): ImageUrl
    {
        if (strlen($path) > $this->limits->maxUrlLength) {
            throw new ImageException('URL exceeds length limit.', 414, 'url_too_long');
        }
        if (preg_match('/%(?![0-9a-fA-F]{2})|%2f|%5c/i', $path)) {
            throw new ImageException('Invalid path encoding.');
        }
        $path = rawurldecode($path);
        if (preg_match('/[\x00-\x1f\x7f%\\\\?#]/', $path)) {
            throw new ImageException('Invalid URL path.');
        }
        if (!preg_match('~\A/(?:([a-zA-Z0-9_-]+)/)?image/upload/(.+)\z~D', $path, $matches)) {
            throw new ImageException('Route not found.', 404, 'not_found');
        }
        $parts = explode('/', $matches[2]);
        $components = [];
        // Version separates ambiguous transformation-like public IDs from actions.
        while (count($parts) > 1 && preg_match('/\A[a-z]+_/', $parts[0])) {
            $components[] = array_shift($parts);
        }
        $version = null;
        if (count($parts) > 1 && preg_match('/\Av[0-9]+\z/', $parts[0])) {
            $version = array_shift($parts);
            if ($components === []) {
                // Cloudinary places the version before the chain. Without this the
                // chain would silently become part of the public ID and 404. Only a
                // run that still parses is a chain, so `v123/c_assets/photo.jpg`
                // keeps its transformation-like folder.
                $chain = [];
                while (count($parts) > 1 && preg_match('/\A[a-z]+_/', $parts[0])) {
                    $chain[] = $parts[0];
                    try {
                        (new TransformationParser($this->limits))->parse(implode('/', $chain));
                    } catch (ImageException) {
                        break;
                    }
                    $components[] = array_shift($parts);
                }
            }
        }
        foreach ($parts as $part) {
            if ($part === '' || str_starts_with($part, '.') || str_contains($part, ':')) {
                throw new ImageException('Invalid public ID.');
            }
        }
        $id = implode('/', $parts);
        $extension = strtolower(pathinfo($id, PATHINFO_EXTENSION));
        $format = null;
        if ($extension !== '') {
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'], true)) {
                throw new ImageException('Unsupported delivery extension.', 415, 'unsupported_format');
            }
            $format = $extension === 'jpeg' ? 'jpg' : $extension;
            $id = substr($id, 0, -strlen($extension) - 1);
        }
        return new ImageUrl($id, $format, (new TransformationParser($this->limits))->parse(implode('/', $components)), $matches[1] !== '' ? $matches[1] : null, $version);
    }
}
