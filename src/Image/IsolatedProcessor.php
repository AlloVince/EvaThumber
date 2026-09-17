<?php

declare(strict_types=1);

namespace EvaThumber\Image;

use EvaThumber\Exception\ImageException;
use EvaThumber\Http\Settings;
use EvaThumber\Transformation\Transformation;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class IsolatedProcessor
{
    public function __construct(private Settings $settings)
    {
    }

    public function write(string $publicId, Transformation $transformation, string $destination, string $format, ?string $identity = null): void
    {
        $process = new Process([
            $this->settings->phpBinary, '-d', 'ffi.enable=true',
            dirname(__DIR__, 2) . '/bin/transform.php',
        ]);
        $process->setTimeout($this->settings->timeout);
        $process->setInput(json_encode([
            'root' => $this->settings->source,
            'publicId' => $publicId,
            'transformation' => $transformation->canonical(),
            'destination' => $destination,
            'format' => $format,
            'identity' => $identity,
            'limits' => get_object_vars($this->settings->limits),
        ], JSON_THROW_ON_ERROR));
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new ImageException('Image processing deadline exceeded.', 504, 'processing_timeout');
        }
        if (!$process->isSuccessful()) {
            $error = json_decode($process->getOutput(), true);
            if (is_array($error) && isset($error['status'], $error['error']) && is_int($error['status']) && is_string($error['error'])) {
                throw new ImageException('Image processing rejected.', $error['status'], $error['error']);
            }
            throw new ImageException('Image processing failed.', 422, 'invalid_image');
        }
    }
}
