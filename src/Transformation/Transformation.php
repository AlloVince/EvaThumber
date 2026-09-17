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
        $normalized = [];
        $deliveryIndex = null;
        foreach ($steps as $step) {
            if ($deliveryIndex !== null) {
                if (array_diff(array_keys($step->parameters), ['q', 'f']) !== []) {
                    throw new ImageException('Pixel transformations must precede delivery parameters.');
                }
                $previous = $normalized[$deliveryIndex]->parameters;
                if (array_intersect_key($previous, $step->parameters) !== []) {
                    throw new ImageException('Duplicate delivery parameter.');
                }
                $normalized[$deliveryIndex] = new Step($previous + $step->parameters);
                continue;
            }
            $normalized[] = $step;
            if ($step->get('q') !== null || $step->get('f') !== null) {
                $deliveryIndex = count($normalized) - 1;
            }
        }
        $this->steps = array_values($normalized);
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
