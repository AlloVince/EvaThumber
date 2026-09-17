<?php

declare(strict_types=1);

namespace EvaThumber\Http;

use EvaThumber\Security\Limits;

final readonly class Settings
{
    public function __construct(
        public string $source,
        public string $cache,
        public Limits $limits = new Limits(),
        public int $timeout = 15,
        public int $cacheBytes = 1073741824,
        public int $cacheEntries = 10000,
        public int $maxAge = 3600,
        public string $phpBinary = PHP_BINARY,
    ) {
        if ($timeout < 1 || $cacheBytes < 1 || $cacheEntries < 1 || $maxAge < 0) {
            throw new \InvalidArgumentException('Invalid service limits.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            getenv('EVATHUMBER_SOURCE') ?: '/data/images',
            getenv('EVATHUMBER_CACHE') ?: '/data/cache',
            new Limits(
                self::integer('MAX_SOURCE_BYTES', 33554432),
                self::integer('MAX_SOURCE_PIXELS', 40000000),
                self::integer('MAX_SOURCE_DIMENSION', 20000),
                self::integer('MAX_OUTPUT_DIMENSION', 4096),
                self::integer('MAX_OUTPUT_PIXELS', 16000000),
                self::integer('MAX_STEPS', 8),
                self::integer('MAX_PARAMETERS', 64),
                self::integer('MAX_URL_LENGTH', 4096),
            ),
            self::integer('TIMEOUT', 15),
            self::integer('CACHE_BYTES', 1073741824),
            self::integer('CACHE_ENTRIES', 10000),
            self::integer('MAX_AGE', 3600),
            getenv('EVATHUMBER_PHP_BINARY') ?: PHP_BINARY,
        );
    }

    private static function integer(string $name, int $default): int
    {
        $value = getenv('EVATHUMBER_' . $name);
        if ($value === false) {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < 1 || $parsed > 2147483647) {
            throw new \InvalidArgumentException('Invalid environment setting: ' . $name);
        }
        return $parsed;
    }
}
