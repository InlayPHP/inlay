<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

final class ToggleButtons extends OptionsField
{
    private bool $multiple = false;

    /** @var array<string|int, string> */
    private array $colors = [];

    protected function type(): string
    {
        return 'toggle-buttons';
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    /**
     * Map option values to semantic colors (e.g. 'success', 'danger').
     *
     * The renderer paints the pressed button with the color named for its
     * value; unknown names fall back to the default accent.
     *
     * @param  array<string|int, string>  $colors
     */
    public function colors(array $colors): static
    {
        $this->colors = $colors;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'multiple' => $this->multiple, 'colors' => $this->colors];
    }
}
