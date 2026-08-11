<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters\QueryBuilder;

use Illuminate\Database\Eloquent\Builder;
use Inlay\Support\Concerns\Configurable;
use JsonSerializable;

abstract class Constraint implements JsonSerializable
{
    use Configurable;

    protected ?string $label = null;

    protected bool $nullable = false;

    /** @var array<string, Operator> */
    private array $customOperators = [];

    protected function __construct(protected readonly string $name)
    {
        $this->applyGlobalConfiguration();
    }

    final public static function make(string $name): static
    {
        return new static($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function nullable(bool $enabled = true): static
    {
        $this->nullable = $enabled;

        return $this;
    }

    /** @param list<Operator> $operators */
    public function withOperators(array $operators): static
    {
        foreach ($operators as $operator) {
            if (! $operator instanceof Operator) {
                throw new \InvalidArgumentException('Custom query operators must be instances of '.Operator::class.'.');
            }
            if (in_array($operator->name(), $this->operators(), true) || isset($this->customOperators[$operator->name()])) {
                throw new \InvalidArgumentException("Duplicate query operator [{$operator->name()}] on constraint [{$this->name}].");
            }
            $this->customOperators[$operator->name()] = $operator;
        }

        return $this;
    }

    final public function apply(Builder $query, string $operator, mixed $value, string $boolean): void
    {
        if (! in_array($operator, $this->operatorNames(), true)) {
            throw new \InvalidArgumentException("Unsupported operator [{$operator}] for query constraint [{$this->name}].");
        }
        $query->where(function (Builder $nested) use ($operator, $value): void {
            if (isset($this->customOperators[$operator])) {
                $this->customOperators[$operator]->apply($nested, $value, $this);

                return;
            }
            $this->applyRule($nested, $operator, $value);
        }, boolean: $boolean);
    }

    /** @return list<string> */
    abstract public function operators(): array;

    abstract protected function type(): string;

    abstract protected function applyRule(Builder $query, string $operator, mixed $value): void;

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'name' => $this->name,
            'label' => $this->label ?? ucwords(str_replace(['_', '-', '.'], ' ', $this->name)),
            'nullable' => $this->nullable,
            'operators' => $this->operatorNames(),
            'operatorDefinitions' => $this->operatorDefinitions(),
        ];
    }

    /**
     * Describe every operator, built-in ones included.
     *
     * Renderers previously inferred which operators take a value, accept a
     * list, or need a date control from hard-coded name lists. PHP owns the
     * operators, so PHP describes them.
     *
     * @return list<array<string, mixed>>
     */
    private function operatorDefinitions(): array
    {
        $definitions = [];
        foreach ($this->operators() as $name) {
            $definitions[] = [
                'name' => $name,
                'label' => ucwords(str_replace('_', ' ', $name)),
                'valueType' => $this->operatorValueType($name),
                'multiple' => $this->operatorAcceptsMany($name),
                'options' => [],
                ...$this->describeOperator($name),
            ];
        }

        foreach ($this->customOperators as $operator) {
            $definitions[] = $operator->jsonSerialize();
        }

        return $definitions;
    }

    /**
     * Refine a built-in operator's metadata.
     *
     * @return array<string, mixed>
     */
    protected function describeOperator(string $operator): array
    {
        return [];
    }

    /** @return 'text'|'number'|'date'|'boolean'|'select'|'none' */
    protected function operatorValueType(string $operator): string
    {
        return in_array($operator, ['filled', 'blank', 'is_true', 'is_false', 'has', 'does_not_have'], true)
            ? 'none'
            : 'text';
    }

    protected function operatorAcceptsMany(string $operator): bool
    {
        return false;
    }

    /** @return list<string> */
    private function operatorNames(): array
    {
        return [...$this->operators(), ...array_keys($this->customOperators)];
    }

    final protected function applyFilled(Builder $query, bool $filled): void
    {
        $query->where(function (Builder $query) use ($filled): void {
            if ($filled) {
                $query->whereNotNull($this->name)->where($this->name, '!=', '');
            } else {
                $query->whereNull($this->name)->orWhere($this->name, '');
            }
        });
    }

    /** @param list<string> $operators
     * @return list<string>
     */
    final protected function withNullableOperators(array $operators): array
    {
        return $this->nullable ? [...$operators, 'filled', 'blank'] : $operators;
    }
}
