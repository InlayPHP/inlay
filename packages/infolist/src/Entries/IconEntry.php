<?php

declare(strict_types=1);

namespace Inlay\Infolists\Entries;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Inlay\Infolists\Entry;
use Inlay\Schemas\Support\SemanticColor;
use InvalidArgumentException;

final class IconEntry extends Entry
{
    /** @var list<string> */
    private const SIZES = ['xs', 'sm', 'md', 'lg', 'xl', '2xl', 'extra-small', 'small', 'medium', 'large', 'extra-large'];

    private bool|Closure|null $boolean = null;

    private string|BackedEnum|Closure|null $icon = null;

    private string|BackedEnum|Closure|false|null $trueIcon = null;

    private string|BackedEnum|Closure|false|null $falseIcon = null;

    private string|Closure|null $trueColor = null;

    private string|Closure|null $falseColor = null;

    private string|BackedEnum|Closure|null $size = null;

    private bool|Closure $listWithLineBreaks = false;

    protected function type(): string
    {
        return 'icon-entry';
    }

    public function boolean(bool|Closure $enabled = true): self
    {
        $this->boolean = $enabled;

        return $this;
    }

    public function icon(string|BackedEnum|Closure|null $icon): self
    {
        if (is_string($icon)) {
            $this->assertIcon($icon, 'icon');
        }
        $this->icon = $icon;

        return $this;
    }

    public function true(string|BackedEnum|Closure|false|null $icon = null, string|Closure|null $color = null): self
    {
        return $this->trueIcon($icon)->trueColor($color);
    }

    public function trueIcon(string|BackedEnum|Closure|false|null $icon): self
    {
        if (is_string($icon)) {
            $this->assertIcon($icon, 'true icon');
        }
        $this->boolean();
        $this->trueIcon = $icon;

        return $this;
    }

    public function false(string|BackedEnum|Closure|false|null $icon = null, string|Closure|null $color = null): self
    {
        return $this->falseIcon($icon)->falseColor($color);
    }

    public function falseIcon(string|BackedEnum|Closure|false|null $icon): self
    {
        if (is_string($icon)) {
            $this->assertIcon($icon, 'false icon');
        }
        $this->boolean();
        $this->falseIcon = $icon;

        return $this;
    }

    public function trueColor(string|Closure|null $color): self
    {
        if (is_string($color)) {
            SemanticColor::assert($color, 'icon entry true color');
        }
        $this->boolean();
        $this->trueColor = $color;

        return $this;
    }

    public function falseColor(string|Closure|null $color): self
    {
        if (is_string($color)) {
            SemanticColor::assert($color, 'icon entry false color');
        }
        $this->boolean();
        $this->falseColor = $color;

        return $this;
    }

    public function size(string|BackedEnum|Closure|null $size): self
    {
        if (is_string($size)) {
            $this->assertSize($size);
        }
        $this->size = $size;

        return $this;
    }

    public function listWithLineBreaks(bool|Closure $enabled = true): self
    {
        $this->listWithLineBreaks = $enabled;

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $icon = $this->resolvedIcon($this->icon, 'icon');
        $trueIcon = $this->resolvedIcon($this->trueIcon, 'true icon');
        $falseIcon = $this->resolvedIcon($this->falseIcon, 'false icon');
        $trueColor = $this->resolvedColor($this->trueColor, 'true color') ?? 'success';
        $falseColor = $this->resolvedColor($this->falseColor, 'false color') ?? 'danger';
        $size = $this->resolvedSize();

        return [
            ...parent::jsonSerialize(),
            'boolean' => $this->resolvedBoolean(),
            'icon' => $icon,
            'trueIcon' => $trueIcon ?? 'check-circle',
            'falseIcon' => $falseIcon ?? 'x-circle',
            'trueColor' => $trueColor,
            'falseColor' => $falseColor,
            'size' => $size,
            'listWithLineBreaks' => $this->resolvePresentationBoolean($this->listWithLineBreaks, 'icon entry list with line breaks'),
        ];
    }

    private function resolvedIcon(string|BackedEnum|Closure|false|null $icon, string $label): string|false|null
    {
        $resolved = $this->evaluate($icon);
        if ($resolved instanceof BackedEnum) {
            $resolved = (string) $resolved->value;
        }
        if ($resolved === false) {
            return false;
        }
        if ($resolved === null) {
            return null;
        }
        if (! is_string($resolved)) {
            throw new \UnexpectedValueException("Icon entry {$label} callbacks must return a string, false, or null.");
        }
        $this->assertIcon($resolved, "resolved {$label}", \UnexpectedValueException::class);

        return $resolved;
    }

    private function resolvedBoolean(): bool
    {
        if ($this->boolean === null) {
            $record = $this->schemaContext?->record;

            return $record instanceof Model && $record->hasCast($this->name(), ['bool', 'boolean']);
        }

        return $this->resolvePresentationBoolean($this->boolean, 'icon entry boolean mode');
    }

    private function resolvedColor(string|Closure|null $color, string $label): ?string
    {
        $resolved = $this->resolvePresentationString($color, "icon entry {$label}");
        if ($resolved !== null) {
            SemanticColor::assert($resolved, "resolved icon entry {$label}", \UnexpectedValueException::class);
        }

        return $resolved;
    }

    private function resolvedSize(): ?string
    {
        $resolved = $this->evaluate($this->size);
        if ($resolved instanceof BackedEnum) {
            $resolved = (string) $resolved->value;
        }
        if ($resolved === null) {
            return null;
        }
        if (! is_string($resolved)) {
            throw new \UnexpectedValueException('Icon entry size callbacks must return a string or null.');
        }
        $this->assertSize($resolved, \UnexpectedValueException::class);

        return $resolved;
    }

    /** @param class-string<\Throwable> $exception */
    private function assertIcon(string $icon, string $label, string $exception = InvalidArgumentException::class): void
    {
        if (trim($icon) === '') {
            throw new $exception("An icon entry {$label} cannot be empty.");
        }
    }

    /** @param class-string<\Throwable> $exception */
    private function assertSize(string $size, string $exception = InvalidArgumentException::class): void
    {
        if (! in_array($size, self::SIZES, true)) {
            throw new $exception("Unsupported icon entry size [{$size}].");
        }
    }
}
