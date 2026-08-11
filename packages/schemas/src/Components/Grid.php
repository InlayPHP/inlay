<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Inlay\Schemas\Component;
use Inlay\Schemas\Concerns\BindsRelationship;
use Inlay\Schemas\Concerns\HasSchema;

final class Grid extends Component
{
    use BindsRelationship;

    use HasSchema;

    /** @param array<string, int>|string|int $nameOrColumns */
    public static function make(array|string|int $nameOrColumns = 2): static
    {
        if (is_int($nameOrColumns) || is_array($nameOrColumns)) {
            return (new self('grid'))->columns($nameOrColumns);
        }

        return new self($nameOrColumns);
    }

    protected function type(): string
    {
        return 'grid';
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), ...$this->serializedSchema()];
    }
}
