<?php

declare(strict_types=1);

namespace Inlay\Forms\Repeater;

use JsonSerializable;

/**
 * One header of a Repeater rendered as a table. Columns line up positionally
 * with the repeater's child fields, so the header row describes every cell.
 */
final class TableColumn implements JsonSerializable
{
    private bool $markedAsRequired = false;

    private string $alignment = 'left';

    private ?string $width = null;

    private function __construct(private readonly string $label)
    {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('A repeater table column label cannot be empty.');
        }
    }

    public static function make(string $label): self
    {
        return new self(trim($label));
    }

    /** Show the required marker in the header instead of on each cell. */
    public function markAsRequired(bool $required = true): self
    {
        $this->markedAsRequired = $required;

        return $this;
    }

    public function alignment(string $alignment): self
    {
        if (! in_array($alignment, ['left', 'center', 'right'], true)) {
            throw new \InvalidArgumentException("Unsupported repeater table column alignment [{$alignment}].");
        }

        $this->alignment = $alignment;

        return $this;
    }

    /** A CSS length such as `12rem`, validated before it reaches the browser. */
    public function width(string $width): self
    {
        if (preg_match('/^\d+(?:\.\d+)?(?:px|rem|em|%|ch)$/', trim($width)) !== 1) {
            throw new \InvalidArgumentException("Invalid repeater table column width [{$width}].");
        }

        $this->width = trim($width);

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'label' => $this->label,
            'markedAsRequired' => $this->markedAsRequired,
            'alignment' => $this->alignment,
            'width' => $this->width,
        ];
    }
}
