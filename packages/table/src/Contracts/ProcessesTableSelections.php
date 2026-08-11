<?php

declare(strict_types=1);

namespace Inlay\Tables\Contracts;

use Closure;
use Inlay\Tables\BulkSelection;
use Inlay\Tables\Data\TableDataRequest;

interface ProcessesTableSelections
{
    public function processSelection(BulkSelection $selection, TableDataRequest $request, Closure $callback, int $chunkSize): int;
}
