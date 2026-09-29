<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use EvaThumber\Exception\ImageException;
use EvaThumber\Image\Pipeline;
use EvaThumber\Security\Limits;
use EvaThumber\Source\LocalSource;
use EvaThumber\Transformation\Parser;

try {
    // Trusted IPC from the service, never a public HTTP entry point.
    $job = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $limits = new Limits(...$job['limits']);
    $local = new LocalSource($job['root'], $limits);
    $source = isset($job['identity']) ? $local->assertIdentity($job['publicId'], $job['identity']) : $local->resolve($job['publicId']);
    (new Pipeline($limits))->write($source, (new Parser($limits))->parse($job['transformation']), $job['destination'], $job['format']);
    $identity = $source->identity;
    unset($source); // Release encoded snapshot before allocating the final revision check.
    $local->assertIdentity($job['publicId'], $identity);
} catch (ImageException $error) {
    // The response body stays generic; without this the reason never reaches a log.
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    echo json_encode(['status' => $error->status, 'error' => $error->error], JSON_THROW_ON_ERROR);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
