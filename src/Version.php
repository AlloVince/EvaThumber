<?php

declare(strict_types=1);

namespace EvaThumber;

/**
 * The released version of the service, reported by /healthz.
 *
 * This constant is the only place the version is written down. The release
 * workflow reads it, so a `v*` tag that does not match it fails the publish
 * step instead of shipping an image that reports someone else's version.
 */
final class Version
{
    public const string VERSION = '2.0.0';
}
