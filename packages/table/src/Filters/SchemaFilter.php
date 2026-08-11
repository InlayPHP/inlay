<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters;

use Closure;
use Inlay\Tables\Filter;
use JsonSerializable;

/**
 * A filter whose controls come from an arbitrary schema instead of one of the
 * built-in filter types. The submitted value is an associative array keyed by
 * field name, and the filter must own its own query callback.
 */
final class SchemaFilter extends Filter
{
    /** @var list<JsonSerializable> */
    private array $schema = [];

    private int $formColumns = 1;

    protected function type(): string
    {
        return 'schema-filter';
    }

    /**
     * Declare the schema components rendered inside the filter form. Any
     * serializable schema component works; Form fields are the common case.
     *
     * @param  list<JsonSerializable>  $schema
     */
    public function schema(array $schema): self
    {
        foreach ($schema as $component) {
            if (! $component instanceof JsonSerializable) {
                throw new \InvalidArgumentException("Schema filter [{$this->name}] components must be JSON serializable.");
            }
        }

        $this->schema = array_values($schema);

        return $this;
    }

    /** Lay the filter's own fields out in a fixed number of columns. */
    public function formColumns(int $columns): self
    {
        if ($columns < 1 || $columns > 6) {
            throw new \InvalidArgumentException("Schema filter [{$this->name}] must use between one and six columns.");
        }

        $this->formColumns = $columns;

        return $this;
    }

    public function query(Closure $callback): static
    {
        return parent::query($callback);
    }

    /** @internal Schema filters never map onto a column automatically. */
    public function assertUsable(): void
    {
        if ($this->schema === []) {
            throw new \LogicException("Schema filter [{$this->name}] must declare a schema.");
        }
        if (! $this->hasQueryCallback()) {
            throw new \LogicException("Schema filter [{$this->name}] must declare a query callback.");
        }
    }

    /**
     * Describe each filled field separately, so every one can be removed on its
     * own from the indicator row.
     *
     * @return array<string, string>|null
     */
    protected function defaultIndicators(mixed $value): ?array
    {
        if (! is_array($value)) {
            return [];
        }

        $labels = $this->fieldLabels();
        $indicators = [];
        foreach ($value as $field => $item) {
            if (! is_string($field) || $item === null || $item === '' || $item === [] || is_array($item)) {
                continue;
            }
            $display = is_bool($item) ? ($item ? 'Yes' : 'No') : (string) $item;
            $indicators[$field] = ($labels[$field] ?? ucwords(str_replace(['_', '-'], ' ', $field))).': '.$display;
        }

        return $indicators;
    }

    /** @return array<string, string> */
    private function fieldLabels(): array
    {
        $labels = [];
        foreach ($this->schema as $component) {
            $payload = $component->jsonSerialize();
            if (is_array($payload) && is_string($payload['name'] ?? null) && is_string($payload['label'] ?? null)) {
                $labels[$payload['name']] = $payload['label'];
            }
        }

        return $labels;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'schema' => $this->schema,
            'formColumns' => $this->formColumns,
        ];
    }
}
