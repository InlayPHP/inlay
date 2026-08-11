<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use InvalidArgumentException;

final class ChartWidget extends Widget
{
    private string $chartType = 'line';

    /** @var list<string> */
    private array $labels = [];

    /** @var list<array{label: string, data: list<int|float>, color: string|null}> */
    private array $datasets = [];

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function chartType(string $type): self
    {
        if (! in_array($type, ['line', 'bar', 'doughnut'], true)) {
            throw new InvalidArgumentException('A chart widget type must be line, bar, or doughnut.');
        }
        $this->chartType = $type;

        return $this;
    }

    /** @param list<string> $labels */
    public function labels(array $labels): self
    {
        $this->labels = array_values(array_map('strval', $labels));

        return $this;
    }

    /** @param list<int|float> $data */
    public function dataset(string $label, array $data, ?string $color = null): self
    {
        foreach ($data as $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException('Chart dataset values must be numeric.');
            }
        }
        $this->datasets[] = ['label' => $label, 'data' => array_values($data), 'color' => $color];

        return $this;
    }

    protected function type(): string
    {
        return 'chart';
    }

    protected function payload(): array
    {
        return ['chartType' => $this->chartType, 'labels' => $this->labels, 'datasets' => $this->datasets];
    }
}
