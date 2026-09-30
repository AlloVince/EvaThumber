<?php

declare(strict_types=1);

namespace EvaThumber;

/**
 * The released version of the service, reported by /healthz.
 *
 * This constant is the only place the version is written down: the release job
 * derives the v* tag and the published image tags from it, so a version that
 * is not a publishable semver, or that does not move forward, fails the release
 * step instead of shipping an image that reports someone else's version.
 */
final class Version
{
    public const string VERSION = '2.0.1';
}
