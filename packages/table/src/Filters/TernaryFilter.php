<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters;

use Inlay\Tables\Filter;

final class TernaryFilter extends Filter
{
    private string $trueLabel = 'Yes';

    private string $falseLabel = 'No';

    protected function type(): string
    {
        return 'ternary-filter';
    }

    public function trueLabel(string $label): self
    {
        $this->trueLabel = $label;

        return $this;
    }

    public function falseLabel(string $label): self
    {
        $this->falseLabel = $label;

        return $this;
    }

    protected function indicatorValue(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return in_array($value, [true, 1, '1', 'true'], true) ? $this->trueLabel : $this->falseLabel;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'trueLabel' => $this->trueLabel, 'falseLabel' => $this->falseLabel];
    }
}
