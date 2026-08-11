<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use Inlay\Tables\Column;
use JsonSerializable;

interface ColumnComponent extends JsonSerializable
{
    /** @return list<Column> */
    public function columns(): array;
}
