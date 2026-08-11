<?php

declare(strict_types=1);

namespace Inlay\Tables\Views;

use Illuminate\Database\ConnectionInterface;
use Inlay\Tables\Contracts\TableViewStore;
use Inlay\Tables\Table;

/**
 * Durable owner-scoped table-view storage.
 *
 * Authentication and tenancy stay outside this driver: the Table supplies the
 * already-authorized owner key, while the unique table/owner/name scope keeps
 * one visitor from seeing another visitor's presets.
 */
final class DatabaseTableViewStore implements TableViewStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $tableName = 'inlay_table_views',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tableName) !== 1) {
            throw new \InvalidArgumentException('The table-view store table name must be a safe SQL identifier.');
        }
    }

    /** @return list<TableView> */
    public function all(Table $table, string|int $owner): array
    {
        $rows = $this->connection->table($this->tableName)
            ->where('table_name', $table->name())
            ->where('owner_key', (string) $owner)
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->orderBy('id')
            ->get();

        return array_values(array_filter(array_map(function (object $row): ?TableView {
            $query = json_decode((string) $row->query, true);
            if (! is_array($query)) {
                return null;
            }

            try {
                return TableView::fromArray([
                    'name' => (string) $row->name,
                    'label' => (string) $row->label,
                    'description' => $row->description === null ? null : (string) $row->description,
                    'query' => $query,
                ])->default((bool) $row->is_default)->markPersonal((string) $row->id);
            } catch (\InvalidArgumentException) {
                // A stale view must never take down a page after a deployment.
                return null;
            }
        }, $rows->all()), static fn (?TableView $view): bool => $view instanceof TableView));
    }

    public function save(Table $table, string|int $owner, TableView $view, ?string $originalName = null): TableView
    {
        if (! $view->isPersonal()) {
            throw new \InvalidArgumentException('Only personal table views may be stored.');
        }

        $tableName = $table->name();
        $owner = (string) $owner;
        if ($owner === '') {
            throw new \InvalidArgumentException('A table-view owner key is required.');
        }
        $payload = $view->jsonSerialize();
        $now = date('Y-m-d H:i:s');
        $existing = $this->connection->table($this->tableName)
            ->where('table_name', $tableName)
            ->where('owner_key', $owner)
            ->where('name', $originalName ?? $view->name())
            ->first();

        if ($originalName !== null && $originalName !== $view->name()) {
            $nameTaken = $this->connection->table($this->tableName)
                ->where('table_name', $tableName)
                ->where('owner_key', $owner)
                ->where('name', $view->name())
                ->exists();

            if ($nameTaken) {
                throw new \InvalidArgumentException('A personal table view with this name already exists.');
            }
        }

        $data = [
            'table_name' => $tableName,
            'owner_key' => $owner,
            'name' => $view->name(),
            'label' => $payload['label'],
            'description' => $payload['description'],
            'query' => json_encode($view->queryState(), JSON_THROW_ON_ERROR),
            'is_default' => (bool) ($payload['default'] ?? false),
            'updated_at' => $now,
        ];

        $this->connection->transaction(function () use ($existing, $data, $now, $tableName, $owner): void {
            $records = $this->connection->table($this->tableName);
            if ($data['is_default']) {
                $records->where('table_name', $tableName)
                    ->where('owner_key', $owner)
                    ->update(['is_default' => false, 'updated_at' => $now]);
            }
            if ($existing === null) {
                $records->insert([...$data, 'created_at' => $now]);

                return;
            }
            $records->where('id', $existing->id)->update($data);
        });

        $row = $this->connection->table($this->tableName)
            ->where('table_name', $tableName)
            ->where('owner_key', $owner)
            ->where('name', $view->name())
            ->first();
        if ($row === null) {
            throw new \UnexpectedValueException('The saved table view could not be reloaded.');
        }

        return $view->markPersonal((string) $row->id);
    }

    public function delete(Table $table, string|int $owner, string $name): void
    {
        $this->connection->table($this->tableName)
            ->where('table_name', $table->name())
            ->where('owner_key', (string) $owner)
            ->where('name', $name)
            ->delete();
    }
}
