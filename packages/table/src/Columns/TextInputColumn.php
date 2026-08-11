<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use Inlay\Tables\Column;

final class TextInputColumn extends Column
{
    private string $inputType = 'text';

    protected function type(): string
    {
        return 'text-input-column';
    }

    public function isEditable(): bool
    {
        return true;
    }

    public function typeAttribute(string $type): self
    {
        $this->inputType = $type;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'inputType' => $this->inputType];
    }
}
