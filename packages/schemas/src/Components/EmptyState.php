<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Closure;
use Inlay\Schemas\Component;
use Inlay\Schemas\Concerns\HasExtraActions;
use Inlay\Schemas\Concerns\HasSchema;
use Inlay\Schemas\Concerns\HasSchemaSlots;
use Inlay\Schemas\Support\SemanticColor;

final class EmptyState extends Component
{
    use HasExtraActions;
    use HasSchema;
    use HasSchemaSlots;

    private string|Closure|null $description = null;

    private string|Closure|null $icon = null;

    private bool|Closure $contained = true;

    private string|Closure|null $iconColor = null;

    private string $iconSize = 'medium';

    private string $headingSize = 'medium';

    protected function type(): string
    {
        return 'empty-state';
    }

    public function description(string|Closure|null $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function icon(string|Closure|null $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function contained(bool|Closure $enabled = true): self
    {
        $this->contained = $enabled;

        return $this;
    }

    /** A closure resolves against the schema context, like every other guard. */
    public function iconColor(string|Closure|null $color): self
    {
        if (is_string($color)) {
            SemanticColor::assert($color, 'empty-state icon color');
        }

        $this->iconColor = $color;

        return $this;
    }

    public function iconSize(string $size): self
    {
        if (! in_array($size, ['small', 'medium', 'large'], true)) {
            throw new \InvalidArgumentException("Unsupported empty-state icon size [{$size}].");
        }

        $this->iconSize = $size;

        return $this;
    }

    /** Scale the heading without leaving PHP for a class name. */
    public function headingSize(string $size): self
    {
        if (! in_array($size, ['small', 'medium', 'large'], true)) {
            throw new \InvalidArgumentException("Unsupported empty-state heading size [{$size}].");
        }

        $this->headingSize = $size;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            ...$this->serializedSchema(),
            ...$this->serializedSchemaSlots(),
            ...$this->serializedExtraActions(),
            'description' => $this->resolvePresentationString($this->description, 'empty-state description'),
            'icon' => $this->resolvePresentationString($this->icon, 'empty-state icon'),
            'iconColor' => $this->resolvedIconColor(),
            'iconSize' => $this->iconSize,
            'headingSize' => $this->headingSize,
            'contained' => $this->resolvePresentationBoolean($this->contained, 'empty-state contained'),
        ];
    }

    private function resolvedIconColor(): ?string
    {
        $color = $this->resolvePresentationString($this->iconColor, 'empty-state icon color');
        if ($color !== null) {
            SemanticColor::assert($color, 'resolved empty-state icon color', \UnexpectedValueException::class);
        }

        return $color;
    }
}
