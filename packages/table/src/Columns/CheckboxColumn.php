<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use Inlay\Tables\Column;

final class CheckboxColumn extends Column
{
    protected function type(): string
    {
        return 'checkbox-column';
    }

    public function isEditable(): bool
    {
        return true;
    }
}
