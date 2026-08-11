<?php

declare(strict_types=1);

namespace Inlay\Tables\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Inlay\Actions\Action;
use Inlay\Support\Testing\Assertions;
use Inlay\Tables\Column;
use Inlay\Tables\Filter;
use Inlay\Tables\Table;

final class TableTester
{
    private function __construct(private Table $table) {}

    public static function make(Table $table): self
    {
        return new self($table);
    }

    public function table(): Table
    {
        return $this->table;
    }

    public function replace(Table $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function assertTableColumnExists(string $name, ?Closure $check = null): self
    {
        $column = $this->table->getColumn($name);
        Assertions::true($column instanceof Column, "Expected table column [{$name}] to exist.");
        if ($check !== null) {
            Assertions::true(
                $check($column) === true,
                "Table column [{$name}] exists, but its configuration assertion failed.",
            );
        }

        return $this;
    }

    public function assertTableColumnDoesNotExist(string $name): self
    {
        Assertions::true($this->table->getColumn($name) === null, "Expected table column [{$name}] not to exist.");

        return $this;
    }

    public function assertTableFilterExists(string $name, ?Closure $check = null): self
    {
        $filter = $this->table->getFilter($name);
        Assertions::true($filter instanceof Filter, "Expected table filter [{$name}] to exist.");
        if ($check !== null) {
            Assertions::true(
                $check($filter) === true,
                "Table filter [{$name}] exists, but its configuration assertion failed.",
            );
        }

        return $this;
    }

    public function assertTableActionExists(string $name, ?Closure $check = null): self
    {
        $action = $this->table->getAction($name);
        Assertions::true($action !== null, "Expected table action [{$name}] to exist.");
        if ($check !== null) {
            Assertions::true(
                $check($action) === true,
                "Table action [{$name}] exists, but its configuration assertion failed.",
            );
        }

        return $this;
    }

    public function assertTableHeaderActionExists(string $name, ?Closure $check = null): self
    {
        return $this->assertAction(
            $this->table->getHeaderAction($name),
            $name,
            'header',
            $check,
        );
    }

    public function assertTableBulkActionExists(string $name, ?Closure $check = null): self
    {
        return $this->assertAction(
            $this->table->getBulkAction($name),
            $name,
            'bulk',
            $check,
        );
    }

    public function assertCountTableRecords(int $count): self
    {
        Assertions::same($count, count($this->rows()), 'The table record count does not match.');

        return $this;
    }

    /** @param iterable<mixed> $records */
    public function assertCanSeeTableRecords(iterable $records, bool $inOrder = false): self
    {
        $expected = $this->recordKeys($records);
        $actual = $this->rowKeys();
        foreach ($expected as $key) {
            Assertions::true(in_array($key, $actual, true), "Expected table record [{$key}] to be visible.");
        }
        if ($inOrder) {
            $visibleOrder = array_values(array_filter($actual, fn (string $key): bool => in_array($key, $expected, true)));
            Assertions::same($expected, $visibleOrder, 'Visible table records are not in the expected order.');
        }

        return $this;
    }

    /** @param iterable<mixed> $records */
    public function assertCanNotSeeTableRecords(iterable $records): self
    {
        $actual = $this->rowKeys();
        foreach ($this->recordKeys($records) as $key) {
            Assertions::true(! in_array($key, $actual, true), "Expected table record [{$key}] not to be visible.");
        }

        return $this;
    }

    public function assertTableColumnStateSet(string $column, mixed $expected, Model|string|int $record): self
    {
        $key = $this->recordKey($record);
        $row = collect($this->rows())->first(
            fn (array $row): bool => (string) ($row[$this->primaryKey()] ?? '') === $key,
        );
        Assertions::true(is_array($row), "Table record [{$key}] is not visible.");
        $missing = new \stdClass;
        $presentation = Arr::get($row, "__inlay.columns.{$column}.state", $missing);
        $actual = $presentation === $missing ? Arr::get($row, $column) : $presentation;
        Assertions::same($expected, $actual, "Table column [{$column}] state for record [{$key}] does not match.");

        return $this;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->table->jsonSerialize()['rows'];
    }

    private function primaryKey(): string
    {
        return $this->table->jsonSerialize()['primaryKey'];
    }

    /** @return list<string> */
    private function rowKeys(): array
    {
        $key = $this->primaryKey();

        return array_map(static fn (array $row): string => (string) ($row[$key] ?? ''), $this->rows());
    }

    /** @param iterable<mixed> $records @return list<string> */
    private function recordKeys(iterable $records): array
    {
        $keys = [];
        foreach ($records as $record) {
            $keys[] = $this->recordKey($record);
        }

        return $keys;
    }

    private function recordKey(mixed $record): string
    {
        if ($record instanceof Model) {
            return (string) $record->getKey();
        }
        if (is_array($record)) {
            return (string) ($record[$this->primaryKey()] ?? '');
        }
        if (is_string($record) || is_int($record)) {
            return (string) $record;
        }

        Assertions::fail('Table record assertions accept Eloquent models, row arrays, strings, or integers.');
    }

    private function assertAction(?Action $action, string $name, string $scope, ?Closure $check): self
    {
        Assertions::true($action instanceof Action, "Expected table {$scope} action [{$name}] to exist.");
        if ($check !== null) {
            Assertions::true(
                $check($action) === true,
                "Table {$scope} action [{$name}] exists, but its configuration assertion failed.",
            );
        }

        return $this;
    }
}
