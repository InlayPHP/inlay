<?php

declare(strict_types=1);

namespace Inlay;

use Inlay\Concerns\HasSafeAttributes;
use Inlay\Support\Condition;
use InvalidArgumentException;
use JsonSerializable;

final class NavigationGroup implements JsonSerializable
{
    use HasSafeAttributes;

    private ?string $label = null;

    private ?string $icon = null;

    private int $sort = 0;

    private bool $collapsible = true;

    private bool $collapsed = false;

    private bool $visible = true;

    private ?Condition $visibleWhen = null;

    /** @var list<NavigationItem> */
    private array $items = [];

    private function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A navigation group name cannot be empty.');
        }

        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function labelText(): string
    {
        return $this->label ?? ucwords(str_replace(['_', '-'], ' ', $this->name));
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function sort(int $sort): self
    {
        $this->sort = $sort;

        return $this;
    }

    public function sortOrder(): int
    {
        return $this->sort;
    }

    /** @return list<NavigationItem> */
    public function itemsList(): array
    {
        return $this->items;
    }

    public function collapsible(bool $collapsible = true): self
    {
        $this->collapsible = $collapsible;

        return $this;
    }

    public function collapsed(bool $collapsed = true): self
    {
        $this->collapsed = $collapsed;

        return $this;
    }

    public function visible(bool $visible = true): self
    {
        $this->visible = $visible;

        return $this;
    }

    public function visibleWhen(Condition|string $path, mixed $value = true, string $operator = 'equals'): self
    {
        $this->visibleWhen = $path instanceof Condition ? $path : Condition::make($path, $value, $operator);

        return $this;
    }

    /** @param list<NavigationItem> $items */
    public function items(array $items): self
    {
        self::assertItems($items);
        $this->items = array_values($items);

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->labelText(),
            'icon' => $this->icon,
            'sort' => $this->sort,
            'collapsible' => $this->collapsible,
            'collapsed' => $this->collapsed,
            'visible' => $this->visible,
            'visibleWhen' => $this->visibleWhen,
            'extraAttributes' => (object) $this->serializedExtraAttributes(),
            'items' => self::sortedItems($this->items),
        ];
    }

    /** @param list<NavigationItem> $items */
    private static function assertItems(array $items): void
    {
        $names = [];

        foreach ($items as $item) {
            if (! $item instanceof NavigationItem) {
                throw new InvalidArgumentException('Navigation group items must be NavigationItem instances.');
            }

            if (isset($names[$item->name()])) {
                throw new InvalidArgumentException("Duplicate navigation item [{$item->name()}].");
            }

            $names[$item->name()] = true;
        }
    }

    /** @param list<NavigationItem> $items @return list<NavigationItem> */
    private static function sortedItems(array $items): array
    {
        usort($items, fn (NavigationItem $left, NavigationItem $right): int => [
            $left->sortOrder(),
            strtolower($left->labelText()),
            $left->name(),
        ] <=> [
            $right->sortOrder(),
            strtolower($right->labelText()),
            $right->name(),
        ]);

        return $items;
    }
}
