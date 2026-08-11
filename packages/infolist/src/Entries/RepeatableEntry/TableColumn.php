<?php

declare(strict_types=1);

namespace Inlay\Infolists\Entries\RepeatableEntry;

use Inlay\Schemas\Support\ContentAlignment;
use InvalidArgumentException;
use JsonSerializable;

final class TableColumn implements JsonSerializable
{
    private bool $hiddenHeaderLabel = false;

    private bool $wrapHeader = false;

    private string $alignment = 'left';

    private ?string $width = null;

    private function __construct(private readonly string $label)
    {
        if (trim($label) === '') {
            throw new InvalidArgumentException('A repeatable entry table column label cannot be empty.');
        }
    }

    public static function make(string $label): self
    {
        return new self(trim($label));
    }

    public function hiddenHeaderLabel(bool $hidden = true): self
    {
        $this->hiddenHeaderLabel = $hidden;

        return $this;
    }

    public function wrapHeader(bool $enabled = true): self
    {
        $this->wrapHeader = $enabled;

        return $this;
    }

    public function alignment(string $alignment): self
    {
        ContentAlignment::assert($alignment, 'repeatable entry table column alignment');
        $this->alignment = $alignment;

        return $this;
    }

    public function width(string $width): self
    {
        $width = trim($width);
        if (preg_match('/^\d+(?:\.\d+)?(?:px|rem|em|%|ch)$/', $width) !== 1) {
            throw new InvalidArgumentException("Invalid repeatable entry table column width [{$width}].");
        }

        $this->width = $width;

        return $this;
    }

    /** @return array{label: string, hiddenHeaderLabel: bool, wrapHeader: bool, alignment: string, width: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'label' => $this->label,
            'hiddenHeaderLabel' => $this->hiddenHeaderLabel,
            'wrapHeader' => $this->wrapHeader,
            'alignment' => $this->alignment,
            'width' => $this->width,
        ];
    }
}
