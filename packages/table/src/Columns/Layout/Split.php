<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns\Layout;

final class Split extends Component
{
    private ?string $from = null;

    public function from(?string $breakpoint): self
    {
        if ($breakpoint !== null && ! in_array($breakpoint, ['sm', 'md', 'lg', 'xl', '2xl'], true)) {
            throw new \InvalidArgumentException("Unsupported split breakpoint [{$breakpoint}].");
        }
        $this->from = $breakpoint;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'from' => $this->from];
    }

    protected function type(): string
    {
        return 'split-layout';
    }
}
