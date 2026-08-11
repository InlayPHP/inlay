<?php

declare(strict_types=1);

namespace Inlay\Infolists\Entries;

use Inlay\Infolists\Entry;
use InvalidArgumentException;

final class ColorEntry extends Entry
{
    private bool $copyable = false;

    private ?string $copyMessage = null;

    private int $copyMessageDuration = 2000;

    protected function type(): string
    {
        return 'color-entry';
    }

    public function copyable(bool $enabled = true, ?string $message = null, int $duration = 2000): self
    {
        if ($duration < 0) {
            throw new InvalidArgumentException('Copy message duration cannot be negative.');
        }

        $this->copyable = $enabled;
        $this->copyMessage = $message;
        $this->copyMessageDuration = $duration;

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'copyable' => $this->copyable,
            'copyMessage' => $this->copyMessage,
            'copyMessageDuration' => $this->copyMessageDuration,
        ];
    }
}
