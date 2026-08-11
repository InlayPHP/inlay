<?php

declare(strict_types=1);

namespace Inlay\Tables\Exports;

use Closure;
use Inlay\Support\ClosureEvaluator;
use JsonSerializable;

final class ExportColumn implements JsonSerializable
{
    private string|Closure|null $label = null;

    private ?Closure $stateUsing = null;

    protected function __construct(private readonly string $name)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid export column [{$name}].");
        }
    }

    public static function make(string $name): static
    {
        return new static(trim($name));
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(string|Closure $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function stateUsing(Closure $callback): static
    {
        $this->stateUsing = $callback;

        return $this;
    }

    public function resolveLabel(string $fallback): string
    {
        $value = $this->label instanceof Closure
            ? ClosureEvaluator::evaluate($this->label, ['column' => $this], [self::class => $this], [$this])
            : $this->label;

        if ($value === null) {
            return $fallback;
        }
        if (! is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException("Export column [{$this->name}] label must resolve to a non-empty string.");
        }

        return $value;
    }

    public function resolveState(array $row, mixed $state): mixed
    {
        if ($this->stateUsing === null) {
            return $state;
        }

        return ClosureEvaluator::evaluate($this->stateUsing, [
            'state' => $state,
            'record' => $row,
            'row' => $row,
            'column' => $this,
        ], [self::class => $this], [$state, $row, $this]);
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->resolveLabel(ucwords(str_replace(['_', '-', '.'], ' ', $this->name))),
            'customState' => $this->stateUsing !== null,
        ];
    }
}
