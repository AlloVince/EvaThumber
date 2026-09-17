<?php

declare(strict_types=1);

namespace EvaThumber\Transformation;

use EvaThumber\Exception\ImageException;
use EvaThumber\Security\Limits;

final readonly class Parser
{
    public function __construct(private Limits $limits = new Limits())
    {
    }

    public function parse(string $expression): Transformation
    {
        if (strlen($expression) > $this->limits->maxUrlLength) {
            throw new ImageException('Transformation is too long.', 414, 'url_too_long');
        }
        if ($expression === '') {
            return new Transformation();
        }
        $segments = explode('/', $expression);
        if (count($segments) > $this->limits->maxSteps) {
            throw new ImageException('Too many transformation steps.');
        }
        $steps = [];
        $total = 0;
        foreach ($segments as $segment) {
            $parameters = [];
            foreach (explode(',', $segment) as $token) {
                if (++$total > $this->limits->maxParameters) {
                    throw new ImageException('Too many transformation parameters.');
                }
                if (preg_match('/\A([a-z]+)_(.+)\z/D', $token, $match) !== 1) {
                    throw new ImageException('Malformed transformation parameter.');
                }
                if (isset($parameters[$match[1]])) {
                    throw new ImageException('Duplicate transformation parameter. Use a separate step.');
                }
                $parameters[$match[1]] = $match[2];
            }
            $steps[] = new Step($parameters);
        }
        return new Transformation($steps);
    }
}
