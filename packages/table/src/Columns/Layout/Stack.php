<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns\Layout;

final class Stack extends Component
{
    private string $alignment = 'start';

    private int $space = 1;

    public function alignment(string $alignment): self
    {
        if (! in_array($alignment, ['start', 'center', 'end'], true)) {
            throw new \InvalidArgumentException("Unsupported stack alignment [{$alignment}].");
        }
        $this->alignment = $alignment;

        return $this;
    }

    public function space(int $space): self
    {
        if ($space < 0 || $space > 8) {
            throw new \InvalidArgumentException('Stack spacing must be between 0 and 8.');
        }
        $this->space = $space;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'alignment' => $this->alignment, 'space' => $this->space];
    }

    protected function type(): string
    {
        return 'stack-layout';
    }
}
