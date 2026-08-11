<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use Inlay\Tables\Column;

final class ToggleColumn extends Column
{
    protected function type(): string
    {
        return 'toggle-column';
    }

    public function isEditable(): bool
    {
        return true;
    }
}
