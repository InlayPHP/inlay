<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters\QueryBuilder;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Inlay\Support\ClosureEvaluator;
use JsonSerializable;

final class Operator implements JsonSerializable
{
    private string $label;

    /** @var 'text'|'number'|'date'|'boolean'|'select'|'none' */
    private string $valueType = 'text';

    private bool $multiple = false;

    /** @var array<string|int, string> */
    private array $options = [];

    private ?Closure $query = null;

    private function __construct(private readonly string $name)
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Query operator names must use lowercase letters, numbers, and underscores.');
        }
        $this->label = ucwords(str_replace('_', ' ', $name));
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(string $label): self
    {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('Query operator labels cannot be empty.');
        }
        $this->label = $label;

        return $this;
    }

    /** @param 'text'|'number'|'date'|'boolean'|'select'|'none' $type */
    public function valueType(string $type): self
    {
        if (! in_array($type, ['text', 'number', 'date', 'boolean', 'select', 'none'], true)) {
            throw new \InvalidArgumentException("Unsupported query operator value type [{$type}].");
        }
        $this->valueType = $type;

        return $this;
    }

    /** @param array<string|int, string> $options */
    public function options(array $options, bool $multiple = false): self
    {
        if ($options === []) {
            throw new \InvalidArgumentException('Select query operators require at least one option.');
        }
        foreach ($options as $value => $label) {
            if ((! is_string($value) && ! is_int($value)) || ! is_string($label) || trim($label) === '') {
                throw new \InvalidArgumentException('Query operator options require scalar values and non-empty labels.');
            }
        }
        $this->options = $options;
        $this->multiple = $multiple;
        $this->valueType = 'select';

        return $this;
    }

    public function query(Closure $callback): self
    {
        $this->query = $callback;

        return $this;
    }

    public function apply(Builder $query, mixed $value, Constraint $constraint): void
    {
        if ($this->query === null) {
            throw new \LogicException("Custom query operator [{$this->name}] must define query().");
        }
        $value = $this->normalizeValue($value);
        ClosureEvaluator::evaluate($this->query, [
            'constraint' => $constraint,
            'operator' => $this,
            'query' => $query,
            'value' => $value,
        ], [Builder::class => $query, Constraint::class => $constraint, self::class => $this], [$query, $value, $constraint, $this]);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'valueType' => $this->valueType,
            'multiple' => $this->multiple,
            'options' => array_map(
                fn (string|int $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys($this->options),
                array_values($this->options),
            ),
        ];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($this->valueType === 'none') {
            return null;
        }
        if ($this->multiple) {
            if (! is_array($value) || count($value) > 100) {
                throw new \InvalidArgumentException("Query operator [{$this->name}] requires a bounded list value.");
            }

            return array_map(fn (mixed $item): mixed => $this->normalizeSingleValue($item), array_values($value));
        }

        return $this->normalizeSingleValue($value);
    }

    private function normalizeSingleValue(mixed $value): mixed
    {
        return match ($this->valueType) {
            'number' => is_numeric($value) ? $value + 0 : throw new \InvalidArgumentException("Query operator [{$this->name}] requires a numeric value."),
            'boolean' => is_bool($value) ? $value : match ($value) {
                '1', 1, 'true' => true,
                '0', 0, 'false' => false,
                default => throw new \InvalidArgumentException("Query operator [{$this->name}] requires a boolean value."),
            },
            'select' => $this->normalizeOption($value),
            'text', 'date' => is_scalar($value) ? substr((string) $value, 0, 1000) : throw new \InvalidArgumentException("Query operator [{$this->name}] requires a scalar value."),
            'none' => null,
        };
    }

    private function normalizeOption(mixed $value): string|int
    {
        foreach (array_keys($this->options) as $option) {
            if ((string) $option === (string) $value) {
                return $option;
            }
        }

        throw new \InvalidArgumentException("Query operator [{$this->name}] received an undeclared option.");
    }
}
