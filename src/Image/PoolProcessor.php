<?php

declare(strict_types=1);

namespace EvaThumber\Image;

use EvaThumber\Exception\ImageException;
use EvaThumber\Http\Settings;
use EvaThumber\Transformation\Transformation;

/** Local, versioned, size-bounded IPC. Never exposed as a TCP listener. */
final readonly class PoolProcessor
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * Readiness only: no job, no image, no publication.
     *
     * @return array{idle: int, active: int, queued: int, capacity: int}
     */
    public function probe(float $timeout = 0.5): array
    {
        $socket = @stream_socket_client('unix://' . $this->settings->poolSocket, $errno, $error, $timeout);
        if ($socket === false) {
            throw new ImageException('Transform pool unavailable.', 503, 'processor_unavailable');
        }
        try {
            stream_set_blocking($socket, false);
            $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);
            $wait = static function (bool $writing) use ($socket, $deadline): void {
                $remaining = $deadline - hrtime(true);
                if ($remaining <= 0) {
                    throw new ImageException('Transform pool status timed out.', 503, 'processor_unavailable');
                }
                $read = $writing ? [] : [$socket];
                $write = $writing ? [$socket] : [];
                $except = [];
                if (@stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000),
                    intdiv($remaining % 1_000_000_000, 1000)) !== 1) {
                    throw new ImageException('Transform pool unavailable.', 503, 'processor_unavailable');
                }
            };
            $frame = json_encode(['protocol' => 1, 'op' => 'status'], JSON_THROW_ON_ERROR) . "\n";
            while ($frame !== '') {
                $wait(true);
                $written = @fwrite($socket, $frame);
                if ($written === false || $written === 0) {
                    throw new ImageException('Transform pool unavailable.', 503, 'processor_unavailable');
                }
                $frame = substr($frame, $written);
            }
            $line = '';
            while (!str_contains($line, "\n") && strlen($line) <= 1024) {
                $wait(false);
                $chunk = fread($socket, 1025 - strlen($line));
                if ($chunk === false || $chunk === '') { break; }
                $line .= $chunk;
            }
            $reply = strlen($line) <= 1024 && str_ends_with($line, "\n") ? json_decode($line, true) : null;
            if (!is_array($reply) || ($reply['protocol'] ?? null) !== 1 || ($reply['status'] ?? null) !== 200) {
                throw new ImageException('Transform pool status unavailable.', 503, 'processor_unavailable');
            }
            $counts = [];
            foreach (['idle', 'active', 'queued', 'capacity'] as $field) {
                if (!is_int($reply[$field] ?? null) || $reply[$field] < 0) {
                    throw new ImageException('Transform pool status malformed.', 503, 'processor_unavailable');
                }
                $counts[$field] = $reply[$field];
            }
            return $counts;
        } finally {
            fclose($socket);
        }
    }

    public function write(string $publicId, Transformation $transformation, string $destination, string $format, string $identity): void
    {
        $socket = @stream_socket_client('unix://' . $this->settings->poolSocket, $errno, $error, 0.2);
        if ($socket === false) {
            throw new ImageException('Transform pool unavailable.', 503, 'processor_unavailable');
        }
        try {
            stream_set_blocking($socket, false);
            $deadline = hrtime(true) + ($this->settings->timeout + 3) * 1_000_000_000
                + $this->settings->poolQueueMilliseconds * 1_000_000;
            $wait = static function (bool $writing) use ($socket, $deadline): void {
                $remaining = $deadline - hrtime(true);
                if ($remaining <= 0) {
                    throw new ImageException('Transform pool response timed out.', 503, 'processor_unavailable');
                }
                $read = $writing ? [] : [$socket];
                $write = $writing ? [$socket] : [];
                $except = [];
                if (@stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000),
                    intdiv($remaining % 1_000_000_000, 1000)) !== 1) {
                    throw new ImageException('Transform pool unavailable.', 503, 'processor_unavailable');
                }
            };
            $message = json_encode(['protocol' => 1, 'publicId' => $publicId, 'transformation' => $transformation->canonical(),
                'destination' => $destination, 'format' => $format, 'identity' => $identity], JSON_THROW_ON_ERROR) . "\n";
            if (strlen($message) > 16384) {
                throw new ImageException('Transform pool unavailable.', 503, 'processor_unavailable');
            }
            while ($message !== '') {
                $wait(true);
                $written = @fwrite($socket, $message);
                if ($written === false || $written === 0) {
                    throw new ImageException('Transform pool unavailable.', 503, 'processor_unavailable');
                }
                $message = substr($message, $written);
            }
            $line = '';
            while (!str_contains($line, "\n") && strlen($line) <= 4096) {
                $wait(false);
                $chunk = fread($socket, 4097 - strlen($line));
                if ($chunk === false || $chunk === '') { break; }
                $line .= $chunk;
            }
            $reply = strlen($line) <= 4096 && str_ends_with($line, "\n") ? json_decode($line, true) : null;
            if (!is_array($reply) || ($reply['protocol'] ?? null) !== 1 || !is_int($reply['status'] ?? null)
                || ($reply['status'] !== 200 && ($reply['status'] < 400 || $reply['status'] > 599))) {
                // No publication follows an indeterminate job. The supervisor owns
                // its hard deadline and reaps the worker before releasing a slot.
                throw new ImageException('Transform pool response lost.', 503, 'processor_unavailable');
            }
            if ($reply['status'] !== 200) {
                throw new ImageException('Image processing rejected.', $reply['status'], is_string($reply['error'] ?? null) ? $reply['error'] : 'processing_failed');
            }
        } finally {
            fclose($socket);
        }
    }
}
