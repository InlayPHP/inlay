<?php

declare(strict_types=1);

namespace Inlay\Tables\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Table;
use Symfony\Component\HttpFoundation\Response;

/**
 * A renderer for one table export format.
 *
 * Drivers own the output format (CSV, XLSX, PDF, or an application-specific
 * format), while ExportAction and Table keep ownership of authorization,
 * selection, filtering, sorting, and column state. Drivers must never trust
 * browser-provided column or query names; the supplied Table has already been
 * built from the PHP definition.
 */
interface ExportDriver
{
    public function format(): string;

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $selection
     */
    public function response(
        Table $table,
        Builder $query,
        array $input,
        ExportAction $action,
        ?array $selection = null,
    ): Response;
}
