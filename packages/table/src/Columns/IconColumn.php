<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use Inlay\Tables\Column;

final class IconColumn extends Column
{
    private bool $boolean = false;

    private string $trueIcon = 'check';

    private string $falseIcon = 'x';

    protected function type(): string
    {
        return 'icon-column';
    }

    public function boolean(bool $enabled = true): self
    {
        $this->boolean = $enabled;

        return $this;
    }

    public function trueIcon(string $icon): self
    {
        $this->trueIcon = $this->validateIconName($icon, 'true-state icon');

        return $this;
    }

    public function falseIcon(string $icon): self
    {
        $this->falseIcon = $this->validateIconName($icon, 'false-state icon');

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'boolean' => $this->boolean, 'trueIcon' => $this->trueIcon, 'falseIcon' => $this->falseIcon];
    }
}
