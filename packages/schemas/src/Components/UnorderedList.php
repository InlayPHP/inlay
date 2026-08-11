<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Inlay\Schemas\Component;

final class UnorderedList extends Component
{
    /** @var list<string|Text> */
    private array $items = [];

    private string $size = 'small';

    /** @param string|list<string|Text> $nameOrItems */
    public function __construct(string|array $nameOrItems)
    {
        parent::__construct(is_string($nameOrItems) ? $nameOrItems : 'unordered-list');

        if (is_array($nameOrItems)) {
            $this->items($nameOrItems);
        }
    }

    /** @param string|list<string|Text> $nameOrItems */
    public static function make(string|array $nameOrItems): static
    {
        return new static($nameOrItems);
    }

    protected function type(): string
    {
        return 'unordered-list';
    }

    protected function rendererCategory(): string
    {
        return 'schema';
    }

    /** @param list<string|Text> $items */
    public function items(array $items): self
    {
        foreach ($items as $item) {
            if (! is_string($item) && ! $item instanceof Text) {
                throw new \InvalidArgumentException('Unordered list items must be strings or Text components.');
            }
        }

        $this->items = array_values($items);

        return $this;
    }

    public function size(string $size): self
    {
        if (! in_array($size, ['extra-small', 'small', 'medium', 'large'], true)) {
            throw new \InvalidArgumentException('Unsupported unordered list size.');
        }

        $this->size = $size;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'items' => array_map(
            static fn (string|Text $item): string|array => $item instanceof Text ? $item->jsonSerialize() : $item,
            $this->items,
        ), 'size' => $this->size];
    }
}
