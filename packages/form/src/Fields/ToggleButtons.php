<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

final class ToggleButtons extends OptionsField
{
    private bool $multiple = false;

    protected function type(): string
    {
        return 'toggle-buttons';
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'multiple' => $this->multiple];
    }
}
