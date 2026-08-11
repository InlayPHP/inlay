<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Inlay\Schemas\Component;
use Inlay\Schemas\Concerns\BindsRelationship;
use Inlay\Schemas\Concerns\HasSchema;

final class Group extends Component
{
    use BindsRelationship;

    use HasSchema;

    protected function type(): string
    {
        return 'group';
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), ...$this->serializedSchema()];
    }
}
