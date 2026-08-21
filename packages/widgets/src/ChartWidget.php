<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use InvalidArgumentException;

final class ChartWidget extends Widget
{
    private string $chartType = 'line';

    /** @var list<string> */
    private array $labels = [];

    /** @var list<array{label: string, data: list<int|float|array{0: int|float, 1: int|float}>, color: string|null, options: array<string, mixed>}> */
    private array $datasets = [];

    /** @var array<string, mixed> */
    private array $options = [];

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function chartType(string $type): self
    {
        if (! in_array($type, ['line', 'bar', 'doughnut', 'scatter'], true)) {
            throw new InvalidArgumentException('A chart widget type must be line, bar, doughnut, or scatter.');
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

    /**
     * Add a dataset. Values are numeric, or x/y point pairs for scatter charts.
     *
     * Per-dataset rendering options (fill, borderDash, pointRadius, showLine,
     * backgroundColor, borderColor, ...) are merged into the serialized
     * `options.datasets` bag keyed by dataset label, so renderers can read one
     * options object without a new transport contract.
     *
     * @param  list<int|float|array{0: int|float, 1: int|float}>  $data
     * @param  array<string, mixed>  $options
     */
    public function dataset(string $label, array $data, ?string $color = null, array $options = []): self
    {
        foreach ($data as $value) {
            if (is_int($value) || is_float($value)) {
                continue;
            }
            $pair = is_array($value)
                && count($value) === 2
                && (is_int($value[0]) || is_float($value[0]))
                && (is_int($value[1]) || is_float($value[1]));
            if (! $pair) {
                throw new InvalidArgumentException('Chart dataset values must be numeric or x/y pairs.');
            }
        }
        $this->datasets[] = [
            'label' => $label,
            'data' => array_values($data),
            'color' => $color,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * Chart-level rendering options serialized alongside chartType.
     *
     * Host renderers read this bag for presentation the base contract cannot
     * express: `indexAxis` (horizontal bars), `fill`, `borderDash`,
     * `pointRadius`, `showLine`, and per-dataset overrides under `datasets`
     * keyed by dataset label.
     *
     * @param  array<string, mixed>  $options
     */
    public function options(array $options): self
    {
        $this->options = [...$this->options, ...$options];

        return $this;
    }

    protected function type(): string
    {
        return 'chart';
    }

    protected function payload(): array
    {
        $options = $this->options;
        $datasetOptions = [];
        foreach ($this->datasets as $dataset) {
            if ($dataset['options'] !== []) {
                $datasetOptions[$dataset['label']] = $dataset['options'];
            }
        }
        if ($datasetOptions !== []) {
            $options['datasets'] = [...($options['datasets'] ?? []), ...$datasetOptions];
        }

        return [
            'chartType' => $this->chartType,
            'labels' => $this->labels,
            'datasets' => array_map(static fn (array $dataset): array => [
                'label' => $dataset['label'],
                'data' => $dataset['data'],
                'color' => $dataset['color'],
            ], $this->datasets),
            'options' => $options,
        ];
    }
}
