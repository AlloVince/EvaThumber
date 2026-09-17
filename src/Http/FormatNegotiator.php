<?php

declare(strict_types=1);

namespace EvaThumber\Http;

use EvaThumber\Exception\ImageException;
use Symfony\Component\HttpFoundation\AcceptHeader;

final class FormatNegotiator
{
    public function negotiate(string $accept): string
    {
        $header = AcceptHeader::fromString($accept === '' ? '*/*' : $accept);
        $best = null;
        $quality = 0.0;
        // Prefer webp on ties: lower encoding cost and broadly available raster codec.
        foreach (['webp' => 'image/webp', 'avif' => 'image/avif', 'jpg' => 'image/jpeg', 'png' => 'image/png'] as $format => $mime) {
            $item = $header->get($mime) ?? $header->get('image/*') ?? $header->get('*/*');
            $q = $item?->getQuality() ?? 0.0;
            // Modern codecs require explicit acceptance, not a generic wildcard.
            if (in_array($format, ['webp', 'avif'], true) && !$header->has($mime)) {
                continue;
            }
            if ($q > $quality) {
                $best = $format;
                $quality = $q;
            }
        }
        if ($best === null) {
            throw new ImageException('No acceptable image format.', 406, 'not_acceptable');
        }
        return $best;
    }
}
