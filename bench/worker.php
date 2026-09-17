<?php

declare(strict_types=1);

// Benchmark-only trusted local IPC. Not a network listener or production worker.
require dirname(__DIR__) . '/vendor/autoload.php';

use EvaThumber\Image\Pipeline;
use EvaThumber\Source\LocalSource;
use EvaThumber\Transformation\Parser;

while (($line = fgets(STDIN)) !== false) {
    $job = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
    $source = (new LocalSource($job['root']))->resolve($job['publicId']);
    (new Pipeline())->write($source, (new Parser())->parse($job['transformation']), $job['destination'], $job['format']);
    $usage = getrusage();
    echo json_encode(['ok' => true, 'maxrss' => $usage['ru_maxrss']], JSON_THROW_ON_ERROR) . "\n";
    fflush(STDOUT);
}
