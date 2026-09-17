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
                'q' => preg_match('/\A(?:[1-9][0-9]?|100)\z/', $value) === 1,
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
                $value = (string) self::ratio($value);
            }
            if ($key === 'f' && $value === 'jpeg') {
                $value = 'jpg';
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

    private static function unsupported(string $message): never
    {
        throw new ImageException($message, 400, 'unsupported_transformation');
    }
}
