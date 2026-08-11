<?php

declare(strict_types=1);

namespace Inlay\Infolists\Entries;

use Closure;
use Inlay\Infolists\Concerns\HasTextPresentation;
use Inlay\Infolists\Entry;

final class KeyValueEntry extends Entry
{
    use HasTextPresentation;

    private string|Closure|null $keyLabel = null;

    private string|Closure|null $valueLabel = null;

    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->placeholder('No entries');
    }

    protected function type(): string
    {
        return 'key-value-entry';
    }

    public function keyLabel(string|Closure|null $label): self
    {
        $this->keyLabel = $label;

        return $this;
    }

    public function valueLabel(string|Closure|null $label): self
    {
        $this->valueLabel = $label;

        return $this;
    }

    /**
     * @deprecated Use placeholder() instead.
     */
    public function emptyMessage(string|Closure|null $message): self
    {
        return $this->placeholder($message);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'keyLabel' => $this->resolvePresentationString($this->keyLabel, 'key-value entry key label') ?? 'Key',
            'valueLabel' => $this->resolvePresentationString($this->valueLabel, 'key-value entry value label') ?? 'Value',
        ];
    }
}
