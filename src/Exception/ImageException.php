<?php

declare(strict_types=1);

namespace EvaThumber\Exception;

class ImageException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly string $error = 'invalid_transformation',
    ) {
        parent::__construct($message);
    }
}
