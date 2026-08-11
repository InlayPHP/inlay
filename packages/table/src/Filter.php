<?php

declare(strict_types=1);

namespace Inlay\Tables;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Inlay\Support\ClosureEvaluator;
use Inlay\Support\Concerns\Configurable;
use JsonSerializable;

abstract class Filter implements JsonSerializable
{
    use Configurable;

    protected ?string $label = null;

    protected mixed $default = null;

    protected int $columnSpan = 1;

    private ?Closure $query = null;

    private ?Closure $indicator = null;

    public function __construct(protected readonly string $name)
    {
        $this->applyGlobalConfiguration();
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    abstract protected function type(): string;

    public function name(): string
    {
        return $this->name;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** Span several columns of the filter form grid. */
    public function columnSpan(int $span): static
    {
        if ($span < 1 || $span > 6) {
            throw new \InvalidArgumentException("Filter [{$this->name}] column span must be between one and six.");
        }

        $this->columnSpan = $span;

        return $this;
    }

    public function default(mixed $value = true): static
    {
        $this->default = $value;

        return $this;
    }

    /**
     * Customize the Eloquent query for this filter.
     *
     * The callback may type-hint Builder and receive the submitted value by
     * either the $value parameter name or as its second positional argument.
     */
    public function query(Closure $callback): static
    {
        $this->query = $callback;

        return $this;
    }

    /** @internal */
    final public function hasQueryCallback(): bool
    {
        return $this->query !== null;
    }

    /** @internal */
    final public function applyQueryCallback(Builder $query, mixed $value): void
    {
        if ($this->query === null) {
            throw new \LogicException("Filter [{$this->name}] does not define a query callback.");
        }

        $result = ClosureEvaluator::evaluate(
            $this->query,
            ['query' => $query, 'value' => $value, 'state' => $value, 'filter' => $this],
            [Builder::class => $query, self::class => $this],
            [$query, $value, $this],
        );

        if ($result !== null && $result !== $query) {
            throw new \LogicException("Filter [{$this->name}] query callbacks must return the supplied Builder or null.");
        }
    }

    /**
     * Describe this filter's active state above the table. Return a string for
     * one indicator, an array keyed by sub-field for several removable ones, or
     * null to hide it.
     */
    public function indicateUsing(Closure $callback): static
    {
        $this->indicator = $callback;

        return $this;
    }

    /**
     * Resolve the removable indicators for the submitted value.
     *
     * @return list<array{filter: string, field: string, label: string}>
     */
    final public function indicators(mixed $value): array
    {
        if ($this->indicator === null) {
            $fields = $this->defaultIndicators($value);
            if ($fields !== null) {
                $indicators = [];
                foreach ($fields as $field => $label) {
                    $indicators[] = $this->indicator($this->name.'.'.$field, $label);
                }

                return $indicators;
            }

            $display = $this->indicatorValue($value);

            return $display === null ? [] : [$this->indicator($this->name, $this->resolvedLabel().': '.$display)];
        }

        $resolved = ClosureEvaluator::evaluate(
            $this->indicator,
            ['value' => $value, 'state' => $value, 'filter' => $this],
            [self::class => $this],
            [$value, $this],
        );

        if ($resolved === null || $resolved === '' || $resolved === []) {
            return [];
        }

        if (is_string($resolved)) {
            return [$this->indicator($this->name, $resolved)];
        }

        if (! is_array($resolved)) {
            throw new \UnexpectedValueException("Filter [{$this->name}] indicator callbacks must return a string, array, or null.");
        }

        $indicators = [];
        foreach ($resolved as $field => $label) {
            if (! is_string($field) || $field === '' || ! is_string($label) || trim($label) === '') {
                throw new \UnexpectedValueException("Filter [{$this->name}] indicator arrays must map sub-fields to non-empty labels.");
            }
            $indicators[] = $this->indicator($this->name.'.'.$field, trim($label));
        }

        return $indicators;
    }

    /**
     * Publish one removable indicator per sub-field. Filters that own several
     * inputs override this; returning null falls back to indicatorValue().
     *
     * @return array<string, string>|null
     */
    protected function defaultIndicators(mixed $value): ?array
    {
        return null;
    }

    /**
     * The default indicator text for a submitted value. Filters override this
     * to resolve option labels or their own formatting.
     */
    protected function indicatorValue(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                if ($item === null || $item === '' || is_array($item)) {
                    continue;
                }
                $parts[] = is_string($key) ? $key.' '.$this->scalarValue($item) : $this->scalarValue($item);
            }

            return $parts === [] ? null : implode(', ', $parts);
        }

        return $this->scalarValue($value);
    }

    final protected function resolvedLabel(): string
    {
        return $this->label ?? ucwords(str_replace(['_', '-', '.'], ' ', $this->name));
    }

    private function scalarValue(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
    }

    /** @return array{filter: string, field: string, label: string} */
    private function indicator(string $field, string $label): array
    {
        return ['filter' => $this->name, 'field' => $field, 'label' => $label];
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'name' => $this->name,
            'label' => $this->resolvedLabel(),
            'default' => $this->default,
            'columnSpan' => $this->columnSpan,
        ];
    }
}
