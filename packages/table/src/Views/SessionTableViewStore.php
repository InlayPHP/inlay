<?php

declare(strict_types=1);

namespace Inlay\Tables\Views;

use Inlay\Tables\Contracts\TableViewStore;
use Inlay\Tables\Table;

/** Session-scoped personal view store used by the default TablePage runtime. */
final class SessionTableViewStore implements TableViewStore
{
    private const SESSION_PREFIX = 'inlay.tables.personal_views.';

    /** @return list<TableView> */
    public function all(Table $table, string|int $owner): array
    {
        $session = $this->session();
        if ($session === null) {
            return [];
        }

        $stored = $session->get($this->key($table, $owner), []);
        if (! is_array($stored)) {
            return [];
        }

        $views = [];
        foreach ($stored as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            try {
                $views[] = TableView::fromArray($payload)->markPersonal(is_string($payload['id'] ?? null) ? $payload['id'] : null);
            } catch (\InvalidArgumentException) {
                // A stale or hand-edited session value must not break a table.
            }
        }

        return $views;
    }

    public function save(Table $table, string|int $owner, TableView $view, ?string $originalName = null): TableView
    {
        $session = $this->session();
        if ($session === null) {
            throw new \LogicException('Personal table views require a session-enabled route.');
        }

        $stored = $session->get($this->key($table, $owner), []);
        $stored = is_array($stored) ? $stored : [];
        $next = [];
        $replaced = false;

        foreach ($stored as $payload) {
            if (! is_array($payload)) {
                continue;
            }
            $name = is_string($payload['name'] ?? null) ? $payload['name'] : null;
            if ($name === $originalName || ($originalName === null && $name === $view->name())) {
                $next[] = $view->jsonSerialize();
                $replaced = true;

                continue;
            }
            $next[] = $payload;
        }

        if (! $replaced) {
            $next[] = $view->jsonSerialize();
        }

        $session->put($this->key($table, $owner), array_values($next));

        return $view;
    }

    public function delete(Table $table, string|int $owner, string $name): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }

        $stored = $session->get($this->key($table, $owner), []);
        if (! is_array($stored)) {
            return;
        }

        $session->put($this->key($table, $owner), array_values(array_filter(
            $stored,
            static fn (mixed $payload): bool => ! is_array($payload) || ($payload['name'] ?? null) !== $name,
        )));
    }

    private function key(Table $table, string|int $owner): string
    {
        return self::SESSION_PREFIX.$table->name().'.'.hash('sha256', (string) $owner);
    }

    private function session(): ?\Illuminate\Contracts\Session\Session
    {
        if (! function_exists('request')) {
            return null;
        }

        $request = request();

        return $request->hasSession() ? $request->session() : null;
    }
}
