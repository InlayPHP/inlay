<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters;

use Inlay\Tables\Filter;

final class TextFilter extends Filter
{
    protected function type(): string
    {
        return 'text-filter';
    }
}
