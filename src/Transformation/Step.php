<?php

declare(strict_types=1);

namespace EvaThumber\Transformation;

final readonly class Step
{
    /** @var array<string, string> */
    public array $parameters;

    /** @param array<string, string> $parameters */
    public function __construct(array $parameters)
    {
        $this->parameters = ParameterRules::normalize($parameters);
    }

    /** @return ($default is string ? string : string|null) */
    public function get(string $name, ?string $default = null): ?string
    {
        return $this->parameters[$name] ?? $default;
    }

    public function canonical(): string
    {
        $parts = [];
        foreach ($this->parameters as $name => $value) {
            $parts[] = $name . '_' . $value;
        }
        return implode(',', $parts);
    }
}
