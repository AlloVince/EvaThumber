<?php

declare(strict_types=1);

namespace EvaThumber\Transformation;

use EvaThumber\Exception\ImageException;

final class ParameterRules
{
    /**
     * @param array<string,string> $parameters
     * @return array<string,string>
     */
    public static function normalize(array $parameters): array
    {
        foreach ($parameters as $key => &$value) {
            $valid = match ($key) {
                'c' => in_array($value, ['scale', 'fit', 'fill', 'crop', 'thumb', 'pad', 'limit'], true),
                'w', 'h' => preg_match('/\A(?:[1-9][0-9]{0,4}|0\.[0-9]{1,6})\z/', $value) === 1 && (float) $value > 0,
                'ar' => preg_match('/\A[0-9]+(?:\.[0-9]+)?(?::[0-9]+(?:\.[0-9]+)?)?\z/', $value) === 1 && self::ratio($value) > 0,
                'g' => in_array($value, ['center', 'north', 'south', 'east', 'west', 'north_east', 'north_west', 'south_east', 'south_west'], true),
                'x', 'y' => preg_match('/\A[0-9]{1,5}\z/', $value) === 1,
                'dpr' => is_numeric($value) && (float) $value >= 1 && (float) $value <= 4,
                'a' => in_array($value, ['0', '90', '180', '270', '-90', 'hflip', 'vflip'], true),
                'q' => preg_match('/\A(?:[1-9][0-9]?|100|auto(?::(?:best|good|eco|low))?)\z/', $value) === 1,
                'f' => in_array($value, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'auto'], true),
                'b' => preg_match('/\Argb:[0-9a-fA-F]{6}\z/', $value) === 1 || in_array($value, ['white', 'black', 'red', 'blue', 'green', 'transparent'], true),
                'e' => in_array($value, ['grayscale', 'negate'], true),
                default => false,
            };
            if (!$valid) {
                throw new ImageException('Unsupported transformation: ' . $key . '_' . $value, 400, 'unsupported_transformation');
            }
            if (in_array($key, ['w', 'h', 'dpr', 'x', 'y'], true)) {
                $value = (string) (float) $value;
            }
            if ($key === 'ar') {
                $value = self::decimal(self::ratio($value));
            }
            if ($key === 'f' && $value === 'jpeg') {
                $value = 'jpg';
            }
            if ($key === 'q' && $value === 'auto') {
                $value = 'auto:good';
            }
        }
        unset($value);
        $resize = isset($parameters['w']) || isset($parameters['h']) || isset($parameters['ar']);
        $mode = $parameters['c'] ?? 'scale';
        if (isset($parameters['ar']) && isset($parameters['w'], $parameters['h'])) {
            self::unsupported('Use two of w, h and ar, not all three.');
        }
        if (isset($parameters['ar']) && !isset($parameters['w']) && !isset($parameters['h'])) {
            self::unsupported('Aspect-ratio-only resizing is not yet implemented.');
        }
        if ((isset($parameters['c']) || isset($parameters['dpr'])) && !$resize) {
            self::unsupported('Resize dimensions are required.');
        }
        if (isset($parameters['g']) && !in_array($mode, ['fill', 'crop', 'thumb', 'pad'], true)) {
            self::unsupported('Gravity requires a positioning resize mode.');
        }
        if ($mode === 'thumb' && !isset($parameters['g'])) {
            self::unsupported('Thumb requires explicit gravity.');
        }
        if (isset($parameters['x']) || isset($parameters['y'])) {
            if ($mode !== 'crop' || ($parameters['g'] ?? '') !== 'north_west') {
                self::unsupported('Coordinates currently require c_crop,g_north_west.');
            }
        }
        if (($resize ? 1 : 0) + (isset($parameters['a']) ? 1 : 0) + (isset($parameters['e']) ? 1 : 0) > 1) {
            self::unsupported('Chain resize, rotation and effects in separate components.');
        }
        if (isset($parameters['b']) && $mode !== 'pad') {
            self::unsupported('Background currently requires c_pad.');
        }
        if ($resize && !isset($parameters['c'])) {
            $parameters['c'] = 'scale';
        }
        ksort($parameters);
        return $parameters;
    }

    private static function ratio(string $value): float
    {
        $parts = explode(':', $value);
        $denominator = (float) ($parts[1] ?? 1);
        return $denominator > 0 ? (float) $parts[0] / $denominator : 0;
    }

    /**
     * Canonical text for a ratio, and the only channel by which the ratio
     * reaches the transform subprocess, so it must survive the round trip
     * through float. A plain (string) cast keeps 14 significant digits, which
     * is lossy: 16:9 becomes "1.7777777777778", and 600 / that is 337.4999...
     * instead of 337.5, so w_600,ar_16:9 rounds down to a 600x337 image that is
     * not 16:9. var_export() round trips exactly but may emit exponent notation
     * ("1.0E-5") for extreme ratios, which the `ar` grammar above rejects when
     * the canonical string is parsed again. Fixed notation with the fewest
     * digits that still parse back to the identical double avoids both.
     */
    private static function decimal(float $value): string
    {
        foreach ([15, 16, 17] as $digits) {
            $text = rtrim(rtrim(sprintf('%.' . $digits . 'F', $value), '0'), '.');
            if ($text !== '' && $text !== '-' && (float) $text === $value) {
                return $text;
            }
        }
        $text = rtrim(rtrim(sprintf('%.17F', $value), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    private static function unsupported(string $message): never
    {
        throw new ImageException($message, 400, 'unsupported_transformation');
    }
}
