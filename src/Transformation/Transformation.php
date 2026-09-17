<?php

declare(strict_types=1);

namespace EvaThumber\Transformation;

use EvaThumber\Exception\ImageException;

final readonly class Transformation
{
    /** @var list<Step> */
    public array $steps;

    /** @param array<array-key, Step> $steps */
    public function __construct(array $steps = [])
    {
        if (!array_is_list($steps)) {
            throw new ImageException('Transformation steps must be an ordered list.');
        }
        $this->steps = $steps;
        foreach ($steps as $index => $step) {
            if (($step->get('q') !== null || $step->get('f') !== null) && $index !== count($steps) - 1) {
                throw new ImageException('Delivery quality and format are supported only in the final step.');
            }
        }
    }

    public function canonical(): string
    {
        return implode('/', array_map(static fn (Step $step): string => $step->canonical(), $this->steps));
    }

    public function get(string $name, ?string $default = null): ?string
    {
        foreach (array_reverse($this->steps) as $step) {
            if ($step->get($name) !== null) {
                return $step->get($name);
            }
        }
        return $default;
    }
}
