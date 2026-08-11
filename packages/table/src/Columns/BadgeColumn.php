<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use Inlay\Tables\Column;

final class BadgeColumn extends Column
{
    /** @var array<string|int, string> */
    private array $colors = [];

    /** @var array<string|int, string> */
    private array $labels = [];

    protected function type(): string
    {
        return 'badge-column';
    }

    /** @param array<string|int, string> $colors */
    public function colors(array $colors): self
    {
        $this->colors = $colors;

        return $this;
    }

    /** @param array<string|int, string> $labels */
    public function labels(array $labels): self
    {
        $this->labels = $labels;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'colors' => (object) $this->colors, 'labels' => (object) $this->labels];
    }
}
