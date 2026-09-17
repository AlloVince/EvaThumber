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
        public string $poolSocket = '',
        public int $poolSize = 2,
        public int $workerMaxJobs = 500,
        public int $workerRssMiB = 192,
        public int $poolQueueSize = 4,
        public int $poolQueueMilliseconds = 1000,
        public bool $serverTiming = false,
    ) {
        if ($poolQueueSize < 1 || $poolQueueSize > 64 || $poolQueueMilliseconds < 1 || $poolQueueMilliseconds > 30000) {
            throw new \InvalidArgumentException('Invalid pool queue limits.');
        }
        if ($poolSize < 1 || $poolSize > 16 || $workerMaxJobs < 1 || $workerRssMiB < 32) {
            throw new \InvalidArgumentException('Invalid worker limits.');
        }
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
            getenv('EVATHUMBER_POOL_SOCKET') ?: '',
            self::integer('POOL_SIZE', 2),
            self::integer('WORKER_MAX_JOBS', 500),
            self::integer('WORKER_RSS_MIB', 192),
            self::integer('POOL_QUEUE_SIZE', 4),
            self::integer('POOL_QUEUE_MS', 1000),
            getenv('EVATHUMBER_SERVER_TIMING') === '1',
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
