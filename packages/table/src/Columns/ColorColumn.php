<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use Inlay\Tables\Column;

final class ColorColumn extends Column
{
    protected function type(): string
    {
        return 'color-column';
    }
}
