<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use EvaThumber\Exception\ImageException;
use EvaThumber\Http\Settings;
use EvaThumber\Image\Pipeline;
use EvaThumber\Source\LocalSource;
use EvaThumber\Transformation\Parser;

$settings = Settings::fromEnvironment();
$local = new LocalSource($settings->source, $settings->limits);
$pipeline = new Pipeline($settings->limits);
$parser = new Parser($settings->limits);
// Force FFI/libvips initialization before advertising readiness.
\Jcupitt\Vips\Config::cacheSetMax(0);
echo "{\"protocol\":1,\"ready\":true}\n";
fflush(STDOUT);
while (($line = fgets(STDIN, 16385)) !== false) {
    try {
        $job = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        $source = $local->assertIdentity($job['publicId'], $job['identity']);
        $pipeline->write($source, $parser->parse($job['transformation']), $job['stage'], $job['format']);
        unset($source);
        $local->assertIdentity($job['publicId'], $job['identity']);
        $reply = ['protocol' => 1, 'status' => 200, 'pid' => getmypid()];
    } catch (ImageException $error) {
        $reply = ['protocol' => 1, 'status' => $error->status, 'error' => $error->error];
    } catch (Throwable $error) {
        fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
        $reply = ['protocol' => 1, 'status' => 422, 'error' => 'invalid_image'];
    } finally {
        unset($source);
    }
    echo json_encode($reply, JSON_THROW_ON_ERROR) . "\n";
    fflush(STDOUT);
    gc_collect_cycles();
}
