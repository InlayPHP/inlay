<?php

declare(strict_types=1);

namespace Inlay\Tables\Views;

use JsonSerializable;

/**
 * A named, data-only table query preset.
 *
 * Views are deliberately data-only. They may carry search, sort, filter,
 * column-search, grouping, and page-size defaults, but never a closure or a
 * query builder. The owning Table validates the names against its allow-list
 * before the view reaches a browser or a data source.
 */
final class TableView implements JsonSerializable
{
    /** @var array<string, mixed> */
    private array $query = [];

    private ?string $label = null;

    private ?string $description = null;

    private bool $default = false;

    private bool $personal = false;

    private ?string $id = null;

    private function __construct(private readonly string $name)
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Table view names must use lowercase letters, numbers, underscores, or hyphens.');
        }
    }

    public static function make(string $name): self
    {
        return new self(trim($name));
    }

    /**
     * Hydrate an owner-scoped view from a persistence driver.
     *
     * The public name remains an allow-listed table-view identifier. Drivers
     * should prefix their names (for example, `personal_`) so an owner's
     * records cannot shadow an application-authored view accidentally.
     */
    public static function personal(string $name, string $id): self
    {
        $view = new self(trim($name));
        $view->personal = true;
        $view->id = trim($id) === '' ? null : trim($id);

        return $view;
    }

    /**
     * Hydrate a persisted, data-only view after validating its shape.
     * Persisted defaults are intentionally ignored: only application-authored
     * views may become the table's configured default.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $name = $payload['name'] ?? null;
        if (! is_string($name)) {
            throw new \InvalidArgumentException('Persisted table views require a string name.');
        }

        $view = self::make($name);
        if (isset($payload['label'])) {
            if (! is_string($payload['label'])) {
                throw new \InvalidArgumentException('Persisted table view labels must be strings.');
            }
            $view->label($payload['label']);
        }
        if (array_key_exists('description', $payload)) {
            if ($payload['description'] !== null && ! is_string($payload['description'])) {
                throw new \InvalidArgumentException('Persisted table view descriptions must be strings or null.');
            }
            $view->description($payload['description']);
        }
        $query = $payload['query'] ?? [];
        if (! is_array($query)) {
            throw new \InvalidArgumentException('Persisted table view queries must be arrays.');
        }
        $view->query($query);

        return $view;
    }

    /** Mark a hydrated view as owner-scoped. */
    public function markPersonal(?string $id = null): self
    {
        $this->personal = true;
        $this->id = $id === null || trim($id) === '' ? null : trim($id);

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(string $label): self
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 120) {
            throw new \InvalidArgumentException('Table view labels must contain between 1 and 120 characters.');
        }

        $this->label = $label;

        return $this;
    }

    public function description(?string $description): self
    {
        $description = $description === null ? null : trim($description);
        if ($description !== null && mb_strlen($description) > 240) {
            throw new \InvalidArgumentException('Table view descriptions must be at most 240 characters.');
        }

        $this->description = $description === '' ? null : $description;

        return $this;
    }

    /**
     * Set defaults using the same names as the table's QueryState contract.
     * Page, cursor, loaded, and arbitrary keys are rejected here instead of
     * silently becoming part of a URL or a remote data-source request.
     *
     * @param array<string, mixed> $query
     */
    public function query(array $query): self
    {
        $allowed = ['search', 'sort', 'direction', 'filters', 'columnSearches', 'group', 'groupDirection', 'perPage'];
        $unknown = array_diff(array_keys($query), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Table view query keys are limited to: '.implode(', ', $allowed).'.');
        }

        $this->query = $query;

        return $this;
    }

    /** @param array<string, mixed> $filters */
    public function filters(array $filters): self
    {
        $this->query['filters'] = $filters;

        return $this;
    }

    public function search(string $search): self
    {
        $this->query['search'] = trim($search);

        return $this;
    }

    public function sort(?string $column, string $direction = 'asc'): self
    {
        $direction = strtolower(trim($direction));
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new \InvalidArgumentException('Table view sort direction must be asc or desc.');
        }

        $this->query['sort'] = $column;
        $this->query['direction'] = $direction;

        return $this;
    }

    public function group(?string $group, string $direction = 'asc'): self
    {
        $direction = strtolower(trim($direction));
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new \InvalidArgumentException('Table view group direction must be asc or desc.');
        }

        $this->query['group'] = $group;
        $this->query['groupDirection'] = $direction;

        return $this;
    }

    public function perPage(int|string|null $perPage): self
    {
        if ($perPage !== null && $perPage !== 'all' && (! is_int($perPage) || $perPage < 1 || $perPage > 500)) {
            throw new \InvalidArgumentException('Table view page sizes must be all or an integer between 1 and 500.');
        }

        $this->query['perPage'] = $perPage;

        return $this;
    }

    public function default(bool $enabled = true): self
    {
        $this->default = $enabled;

        return $this;
    }

    /** @return array<string, mixed> */
    public function queryState(): array
    {
        return $this->query;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function isPersonal(): bool
    {
        return $this->personal;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label ?? ucwords(str_replace(['_', '-'], ' ', $this->name)),
            'description' => $this->description,
            'query' => $this->query,
            'default' => $this->default,
            'personal' => $this->personal,
            'id' => $this->id,
        ];
    }
}
