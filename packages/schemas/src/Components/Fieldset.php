<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Inlay\Schemas\Component;
use Inlay\Schemas\Concerns\BindsRelationship;
use Inlay\Schemas\Concerns\HasSchema;

final class Fieldset extends Component
{
    use BindsRelationship;

    use HasSchema;

    private bool $contained = true;

    protected function type(): string
    {
        return 'fieldset';
    }

    public function contained(bool $enabled = true): self
    {
        $this->contained = $enabled;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), ...$this->serializedSchema(), 'contained' => $this->contained];
    }
}
