<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters;

use Inlay\Tables\Filter;

final class DateFilter extends Filter
{
    private bool $range = false;

    protected function type(): string
    {
        return 'date-filter';
    }

    public function range(bool $enabled = true): self
    {
        $this->range = $enabled;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'range' => $this->range];
    }
}
