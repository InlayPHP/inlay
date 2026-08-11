<?php

declare(strict_types=1);

namespace Inlay\Tables\Contracts;

use Inlay\Tables\Data\TableDataRequest;

interface ReordersTableRecords
{
    /** @param list<string|int> $keys */
    public function reorderRecords(array $keys, int $startPosition, TableDataRequest $request): void;
}
