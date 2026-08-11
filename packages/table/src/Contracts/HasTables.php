<?php

declare(strict_types=1);

namespace Inlay\Tables\Contracts;

use Illuminate\Http\Request;
use Inlay\Tables\Table;

interface HasTables
{
    /** @return array<string, Table> */
    public function resolveTables(Request $request): array;

    public function resolveTable(Request $request, ?string $name = null): Table;
}
