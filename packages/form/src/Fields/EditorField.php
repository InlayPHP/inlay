<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Inlay\Forms\Field;

abstract class EditorField extends Field
{
    private ?string $language = null;

    private int $rows = 10;

    public function language(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function rows(int $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'language' => $this->language, 'rows' => $this->rows];
    }
}
