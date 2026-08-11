<?php

declare(strict_types=1);

namespace Inlay\Tables\Contracts;

use Inlay\Tables\Table;
use Inlay\Tables\Views\TableView;

/**
 * Persistence boundary for user-owned table views.
 *
 * The table package ships a session implementation so a page works without a
 * database migration. Applications that need cross-device or long-lived
 * views can bind this contract to a database-backed implementation.
 */
interface TableViewStore
{
    /** @return list<TableView> */
    public function all(Table $table, string|int $owner): array;

    public function save(Table $table, string|int $owner, TableView $view, ?string $originalName = null): TableView;

    public function delete(Table $table, string|int $owner, string $name): void;
}
