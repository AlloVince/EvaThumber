<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use EvaThumber\Http\Kernel;
use EvaThumber\Http\Settings;
use Symfony\Component\HttpFoundation\Request;

$kernel = new Kernel(Settings::fromEnvironment());
$handler = static function () use ($kernel): void {
    $request = Request::createFromGlobals();
    $response = $kernel->handle($request);
    $response->prepare($request)->send();
};

if (function_exists('frankenphp_handle_request')) {
    // Bound persistent worker lifetime. Request and response objects are local to the handler.
    for ($requests = 0; $requests < 500; ++$requests) {
        if (!frankenphp_handle_request($handler)) {
            break;
        }
        gc_collect_cycles();
    }
} else {
    $handler();
}
