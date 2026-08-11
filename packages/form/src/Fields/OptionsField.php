<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Inlay\Forms\Concerns\HasOptions;
use Inlay\Forms\Field;

abstract class OptionsField extends Field
{
    use HasOptions;

    private bool $inline = false;

    public function inline(bool $inline = true): static
    {
        $this->inline = $inline;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'options' => $this->serializedOptions(), 'inline' => $this->inline];
    }
}
