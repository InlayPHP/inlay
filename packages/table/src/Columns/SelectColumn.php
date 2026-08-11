<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use Inlay\Tables\Column;
use Inlay\Tables\Concerns\HasOptions;

final class SelectColumn extends Column
{
    use HasOptions;

    protected function type(): string
    {
        return 'select-column';
    }

    public function isEditable(): bool
    {
        return true;
    }

    public function hasOption(mixed $value): bool
    {
        return array_key_exists($value, $this->options);
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'options' => $this->serializedOptions()];
    }
}
