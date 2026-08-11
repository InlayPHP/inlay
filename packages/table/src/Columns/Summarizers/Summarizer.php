<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns\Summarizers;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Inlay\Support\ClosureEvaluator;
use Inlay\Support\Concerns\Configurable;
use JsonSerializable;

abstract class Summarizer implements JsonSerializable
{
    use Configurable;

    protected ?string $label = null;

    protected ?int $decimalPlaces = null;

    protected ?string $prefix = null;

    protected ?string $suffix = null;

    protected ?string $currency = null;

    private ?Closure $queryCallback = null;

    private ?Closure $usingCallback = null;

    private ?Closure $usingRowsCallback = null;

    private ?string $column = null;

    protected function __construct()
    {
        $this->applyGlobalConfiguration();
    }

    final public static function make(): static
    {
        return new static;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Aggregate a column other than the one this summarizer is attached to.
     *
     * Column summaries take their column from their owner; a table aggregate
     * widget has no owner, so it names the column itself.
     */
    public function column(string $column): static
    {
        $column = trim($column);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \InvalidArgumentException("Unsupported summarizer column [{$column}].");
        }

        $this->column = $column;

        return $this;
    }

    final public function columnName(): ?string
    {
        return $this->column;
    }

    public function numeric(?int $decimalPlaces = null): static
    {
        if ($decimalPlaces !== null && ($decimalPlaces < 0 || $decimalPlaces > 20)) {
            throw new \InvalidArgumentException('Summary decimal places must be between 0 and 20.');
        }
        $this->decimalPlaces = $decimalPlaces;

        return $this;
    }

    public function money(string $currency = 'USD', ?int $decimalPlaces = 2): static
    {
        $this->currency = strtoupper($currency);

        return $this->numeric($decimalPlaces);
    }

    public function prefix(?string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function suffix(?string $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    /**
     * Constrain the aggregate without touching the table's own query, so a
     * column can summarize a subset of the records it lists.
     */
    public function query(Closure $callback): static
    {
        $this->queryCallback = $callback;

        return $this;
    }

    /**
     * Replace the aggregate entirely. The callback receives the scoped Eloquent
     * builder and the column name, and returns the summary value.
     */
    public function using(Closure $callback): static
    {
        $this->usingCallback = $callback;

        return $this;
    }

    /**
     * Replace the page aggregate, which runs over the loaded rows instead of a
     * query. Without it, a `using()` summarizer publishes no page summary.
     */
    public function usingRows(Closure $callback): static
    {
        $this->usingRowsCallback = $callback;

        return $this;
    }

    /** @internal Resolve the query aggregate, honoring query() and using(). */
    final public function resolveQueryValue(Builder $query, string $column): mixed
    {
        if ($this->queryCallback !== null) {
            $result = ClosureEvaluator::evaluate(
                $this->queryCallback,
                ['query' => $query, 'column' => $column, 'summarizer' => $this],
                [Builder::class => $query, self::class => $this],
                [$query, $column, $this],
            );

            if ($result !== null && $result !== $query) {
                throw new \LogicException('Summarizer query callbacks must return the supplied Builder or null.');
            }
        }

        if ($this->usingCallback === null) {
            return $this->calculateQuery($query, $column);
        }

        return ClosureEvaluator::evaluate(
            $this->usingCallback,
            ['query' => $query, 'column' => $column, 'summarizer' => $this],
            [Builder::class => $query, self::class => $this],
            [$query, $column, $this],
        );
    }

    /**
     * @internal Resolve the page aggregate, or null when a custom summarizer
     * declines to summarize the loaded rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{mixed}|null
     */
    final public function resolveRowsValue(array $rows, string $column): ?array
    {
        if ($this->usingRowsCallback !== null) {
            return [ClosureEvaluator::evaluate(
                $this->usingRowsCallback,
                ['rows' => $rows, 'column' => $column, 'summarizer' => $this],
                [self::class => $this],
                [$rows, $column, $this],
            )];
        }

        return $this->usingCallback === null ? [$this->calculateRows($rows, $column)] : null;
    }

    abstract public function calculateQuery(Builder $query, string $column): mixed;

    /** @param list<array<string, mixed>> $rows */
    abstract public function calculateRows(array $rows, string $column): mixed;

    /** @return array<string, mixed> */
    final public function result(mixed $value): array
    {
        return [...$this->jsonSerialize(), 'value' => $value];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'label' => $this->label ?? ucfirst($this->type()),
            'decimalPlaces' => $this->decimalPlaces,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
            'currency' => $this->currency,
        ];
    }

    abstract protected function type(): string;

    /** @param array<string, mixed> $row */
    final protected function valueAtPath(array $row, string $path): mixed
    {
        $value = $row;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<int|float>
     */
    final protected function numericValues(array $rows, string $column): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = $this->valueAtPath($row, $column);
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $values[] = $value + 0;
            }
        }

        return $values;
    }
}
