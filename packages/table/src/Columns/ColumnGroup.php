<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use Closure;
use Inlay\Support\Concerns\Configurable;
use Inlay\Tables\Column;
use JsonSerializable;

final class ColumnGroup implements JsonSerializable
{
    use Configurable;

    /** @var list<Column> */
    private array $groupedColumns = [];

    private string|Closure $alignment = 'center';

    private bool|Closure $wrapHeader = false;

    private string|Closure|null $tooltip = null;

    /** @param list<Column> $columns */
    private function __construct(private readonly string $label, array $columns = [])
    {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('Table column group labels cannot be empty.');
        }
        $this->applyGlobalConfiguration();
        $this->columns($columns);
    }

    /** @param list<Column> $columns */
    public static function make(string $label, array $columns = []): self
    {
        return new self($label, $columns);
    }

    /** @param list<Column> $columns */
    public function columns(array $columns): self
    {
        foreach ($columns as $column) {
            if (! $column instanceof Column) {
                throw new \InvalidArgumentException('Table column groups may contain only columns.');
            }
        }
        $this->groupedColumns = array_values($columns);

        return $this;
    }

    public function alignment(string|Closure $alignment): self
    {
        if (is_string($alignment) && ! in_array($alignment, ['left', 'center', 'right'], true)) {
            throw new \InvalidArgumentException("Unsupported column group alignment [{$alignment}].");
        }
        $this->alignment = $alignment;

        return $this;
    }

    /** Align the group heading to the start of a left-to-right interface. */
    public function alignStart(): self
    {
        return $this->alignment('left');
    }

    public function alignCenter(): self
    {
        return $this->alignment('center');
    }

    /** Align the group heading to the end of a left-to-right interface. */
    public function alignEnd(): self
    {
        return $this->alignment('right');
    }

    public function wrapHeader(bool|Closure $enabled = true): self
    {
        $this->wrapHeader = $enabled;

        return $this;
    }

    public function tooltip(string|Closure|null $tooltip): self
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    /** @return list<Column> */
    public function groupedColumns(): array
    {
        return $this->groupedColumns;
    }

    /** @return array{label: string, columns: list<string>, alignment: string, wrapHeader: bool, tooltip: string|null} */
    public function jsonSerialize(): array
    {
        return [
            'label' => $this->label,
            'columns' => array_map(fn (Column $column): string => $column->name(), $this->groupedColumns),
            'alignment' => $this->resolvedAlignment(),
            'wrapHeader' => $this->resolvedWrapHeader(),
            'tooltip' => $this->resolvedTooltip(),
        ];
    }

    private function evaluatePresentation(mixed $value): mixed
    {
        return $value instanceof Closure
            ? \Inlay\Support\ClosureEvaluator::evaluate($value, ['group' => $this], [self::class => $this], [$this])
            : $value;
    }

    private function resolvedAlignment(): string
    {
        $alignment = $this->evaluatePresentation($this->alignment);
        if (! is_string($alignment) || ! in_array($alignment, ['left', 'center', 'right'], true)) {
            throw new \UnexpectedValueException("Column group [{$this->label}] alignment callbacks must resolve to left, center, or right.");
        }

        return $alignment;
    }

    private function resolvedWrapHeader(): bool
    {
        $wrap = $this->evaluatePresentation($this->wrapHeader);
        if (! is_bool($wrap)) {
            throw new \UnexpectedValueException("Column group [{$this->label}] wrap header callbacks must resolve to a boolean.");
        }

        return $wrap;
    }

    private function resolvedTooltip(): ?string
    {
        $tooltip = $this->evaluatePresentation($this->tooltip);
        if ($tooltip === null) {
            return null;
        }
        if (! is_string($tooltip)) {
            throw new \UnexpectedValueException("Column group [{$this->label}] tooltip callbacks must resolve to a string or null.");
        }

        return $tooltip;
    }
}
