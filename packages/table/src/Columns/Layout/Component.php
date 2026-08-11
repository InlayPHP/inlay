<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns\Layout;

use Inlay\Support\Concerns\Configurable;
use Inlay\Tables\Column;
use Inlay\Tables\Columns\ColumnComponent;

abstract class Component implements ColumnComponent
{
    use Configurable;

    /** @var list<Column|Component> */
    protected array $schema;

    protected ?string $visibleFrom = null;

    protected ?string $hiddenFrom = null;

    protected bool $grow = true;

    /** @param list<Column|Component> $schema */
    final protected function __construct(array $schema)
    {
        foreach ($schema as $component) {
            if (! $component instanceof Column && ! $component instanceof self) {
                throw new \InvalidArgumentException('Table layout children must be columns or layout components.');
            }
        }
        $this->schema = array_values($schema);
        $this->applyGlobalConfiguration();
    }

    /** @param list<Column|Component> $schema */
    final public static function make(array $schema): static
    {
        return new static($schema);
    }

    public function visibleFrom(?string $breakpoint): static
    {
        $this->visibleFrom = $this->breakpoint($breakpoint);

        return $this;
    }

    public function hiddenFrom(?string $breakpoint): static
    {
        $this->hiddenFrom = $this->breakpoint($breakpoint);

        return $this;
    }

    public function grow(bool $enabled = true): static
    {
        $this->grow = $enabled;

        return $this;
    }

    public function columns(): array
    {
        $columns = [];
        foreach ($this->schema as $component) {
            if ($component instanceof Column) {
                $columns[] = $component;
            } else {
                array_push($columns, ...$component->columns());
            }
        }

        return $columns;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'schema' => $this->schema,
            'visibleFrom' => $this->visibleFrom,
            'hiddenFrom' => $this->hiddenFrom,
            'grow' => $this->grow,
        ];
    }

    abstract protected function type(): string;

    private function breakpoint(?string $breakpoint): ?string
    {
        if ($breakpoint !== null && ! in_array($breakpoint, ['sm', 'md', 'lg', 'xl', '2xl'], true)) {
            throw new \InvalidArgumentException("Unsupported responsive breakpoint [{$breakpoint}].");
        }

        return $breakpoint;
    }
}
