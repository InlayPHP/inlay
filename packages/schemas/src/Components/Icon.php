<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Inlay\Schemas\Component;

final class Icon extends Component
{
    private string $color = 'neutral';

    private string $size = 'medium';

    private ?string $tooltip = null;

    protected function type(): string
    {
        return 'icon';
    }

    protected function rendererCategory(): string
    {
        return 'schema';
    }

    public function color(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function size(string $size): self
    {
        if (! in_array($size, ['extra-small', 'small', 'medium', 'large', 'extra-large', '2xl'], true)) {
            throw new \InvalidArgumentException('Unsupported icon size.');
        }

        $this->size = $size;

        return $this;
    }

    public function tooltip(?string $tooltip): self
    {
        if ($tooltip !== null && trim($tooltip) === '') {
            throw new \InvalidArgumentException('Icon tooltip cannot be empty.');
        }

        $this->tooltip = $tooltip;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'icon' => $this->name, 'color' => $this->color, 'size' => $this->size, 'tooltip' => $this->tooltip];
    }
}
