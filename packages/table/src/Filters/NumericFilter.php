<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters;

use Inlay\Tables\Filter;

final class NumericFilter extends Filter
{
    protected function type(): string
    {
        return 'numeric-filter';
    }
}
