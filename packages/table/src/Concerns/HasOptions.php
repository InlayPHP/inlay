<?php

declare(strict_types=1);

namespace Inlay\Tables\Concerns;

trait HasOptions
{
    /** @var array<string|int, string> */
    protected array $options = [];

    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    protected function serializedOptions(): array
    {
        $result = [];
        foreach ($this->options as $value => $label) {
            $result[] = ['value' => $value, 'label' => $label];
        }

        return $result;
    }
}
