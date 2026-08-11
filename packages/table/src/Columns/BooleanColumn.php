<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use Inlay\Tables\Column;

final class BooleanColumn extends Column
{
    protected function type(): string
    {
        return 'boolean-column';
    }
}
