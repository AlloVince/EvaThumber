<?php

declare(strict_types=1);

// Run against an isolated Compose project with data/images/demo.jpg mounted read-only.
require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

$container = $argv[1] ?? throw new InvalidArgumentException('Expected container name and base URL.');
$base = $argv[2] ?? throw new InvalidArgumentException('Expected base URL.');
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$request = static function (string $path) use ($base): array {
    $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $body = file_get_contents($base . $path, false, $context);
    $headers = http_get_last_response_headers();
    if ($body === false || $headers === null) {
        throw new RuntimeException('HTTP request failed.');
    }
    $result = ['status' => (int) explode(' ', $headers[0])[1], 'body' => $body];
    foreach (array_slice($headers, 1) as $header) {
        if (str_contains($header, ':')) {
            [$key, $value] = explode(':', $header, 2);
            $result[strtolower($key)] = trim($value);
        }
    }
    return $result;
};
$version = (string) random_int(100000000, 999999999);
$warm = '/image/upload/w_20/v' . $version . '/demo.jpg';
$cold = '/image/upload/w_21/v' . $version . '/demo.jpg';
$first = $request($warm);
$check($first['status'] === 200 && $first['x-evathumber-cache'] === 'MISS', 'Warmup must be a real MISS.');
$input = new InputStream();
$holder = new Process(['docker', 'exec', '-i', $container, 'php', '-r', <<<'PHP'
$leases = [];
for ($slot = 0; $slot < 8; ++$slot) {
    $handle = fopen('/data/cache/.admission-' . $slot . '.lock', 'c');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) { exit(2); }
    $leases[] = $handle;
}
echo "FULL\n";
fgets(STDIN);
foreach ($leases as $lease) { fclose($lease); }
echo "RELEASED\n";
PHP], input: $input, timeout: 20);
try {
    $holder->start();
    $check($holder->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "FULL\n")), 'Slot holder did not become ready.');
    $hit = $request($warm);
    $check($hit['status'] === 200 && $hit['x-evathumber-cache'] === 'HIT', 'Full slots must not block HIT.');
    $check($hit['body'] === $first['body'] && $hit['etag'] === $first['etag'], 'HIT bytes and ETag must remain unchanged.');
    $busy = $request($cold);
    $check($busy['status'] === 503, 'Full slots must return 503; got ' . $busy['status']);
    $check(json_decode($busy['body'], true, flags: JSON_THROW_ON_ERROR)['error'] === 'processor_busy', 'Expected processor_busy.');
    $check(($busy['retry-after'] ?? null) === '1', 'Expected Retry-After: 1.');
    $input->write("release\n");
    $input->close();
    $check($holder->wait() === 0, 'Slot holder failed to release.');
    $recovered = $request($cold);
    $check($recovered['status'] === 200 && $recovered['x-evathumber-cache'] === 'MISS', 'Released slots must allow a new MISS.');
    $size = getimagesizefromstring($recovered['body']);
    $check($size !== false && $size[0] === 21 && $size['mime'] === 'image/jpeg', 'Recovered response must contain the transformed JPEG.');
    echo "PASS: full slots → HIT 200, cold 503 processor_busy + Retry-After; release → MISS 200 JPEG.\n";
} finally {
    // Close stdin so the remote PHP exits and releases locks even on assertion failure.
    $input->close();
    if ($holder->isRunning()) {
        $holder->wait();
    }
}
