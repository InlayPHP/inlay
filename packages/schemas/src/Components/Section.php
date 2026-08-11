<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Closure;
use Inlay\Schemas\Component;
use Inlay\Schemas\Support\SemanticColor;
use Inlay\Schemas\Concerns\BindsRelationship;
use Inlay\Schemas\Concerns\HasExtraActions;
use Inlay\Schemas\Concerns\HasSchema;
use Inlay\Schemas\Concerns\HasSchemaSlots;

final class Section extends Component
{
    use BindsRelationship;

    use HasExtraActions;
    use HasSchema;
    use HasSchemaSlots;

    private string|Closure|null $description = null;

    private string|Closure|null $icon = null;

    private string|Closure|null $iconColor = null;

    private string $iconSize = 'medium';

    private string $headingSize = 'medium';

    private bool|Closure $aside = false;

    private bool|Closure $compact = false;

    private bool|Closure $secondary = false;

    private bool|Closure $collapsible = false;

    private bool|Closure $collapsed = false;

    private bool $persistCollapsed = false;

    protected function type(): string
    {
        return 'section';
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

    /** A closure resolves against the schema context, like every other guard. */
    public function iconColor(string|Closure|null $color): self
    {
        if (is_string($color)) {
            SemanticColor::assert($color, 'section icon color');
        }

        $this->iconColor = $color;

        return $this;
    }

    public function iconSize(string $size): self
    {
        if (! in_array($size, ['small', 'medium', 'large'], true)) {
            throw new \InvalidArgumentException("Unsupported section icon size [{$size}].");
        }

        $this->iconSize = $size;

        return $this;
    }

    /** Scale the heading without leaving PHP for a class name. */
    public function headingSize(string $size): self
    {
        if (! in_array($size, ['small', 'medium', 'large'], true)) {
            throw new \InvalidArgumentException("Unsupported section heading size [{$size}].");
        }

        $this->headingSize = $size;

        return $this;
    }

    public function aside(bool|Closure $enabled = true): self
    {
        $this->aside = $enabled;

        return $this;
    }

    public function compact(bool|Closure $enabled = true): self
    {
        $this->compact = $enabled;

        return $this;
    }

    public function secondary(bool|Closure $enabled = true): self
    {
        $this->secondary = $enabled;

        return $this;
    }

    public function collapsible(bool|Closure $enabled = true): self
    {
        $this->collapsible = $enabled;

        return $this;
    }

    public function collapsed(bool|Closure $enabled = true): self
    {
        $this->collapsed = $enabled;
        if ($enabled instanceof Closure || $enabled) {
            $this->collapsible = true;
        }

        return $this;
    }

    public function persistCollapsed(bool $enabled = true): self
    {
        $this->persistCollapsed = $enabled;
        $this->collapsible = $this->collapsible || $enabled;

        return $this;
    }

    private function resolvedIconColor(): ?string
    {
        $color = $this->resolvePresentationString($this->iconColor, 'section icon color');
        if ($color !== null) {
            SemanticColor::assert($color, 'resolved section icon color', \UnexpectedValueException::class);
        }

        return $color;
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            ...$this->serializedSchema(),
            ...$this->serializedSchemaSlots(),
            ...$this->serializedExtraActions(),
            'description' => $this->resolvePresentationString($this->description, 'section description'),
            'icon' => $this->resolvePresentationString($this->icon, 'section icon'),
            'iconColor' => $this->resolvedIconColor(),
            'iconSize' => $this->iconSize,
            'headingSize' => $this->headingSize,
            'aside' => $this->resolvePresentationBoolean($this->aside, 'section aside'),
            'compact' => $this->resolvePresentationBoolean($this->compact, 'section compact'),
            'secondary' => $this->resolvePresentationBoolean($this->secondary, 'section secondary'),
            'collapsible' => $this->resolvePresentationBoolean($this->collapsible, 'section collapsible'),
            'collapsed' => $this->resolvePresentationBoolean($this->collapsed, 'section collapsed'),
            'persistCollapsed' => $this->persistCollapsed,
        ];
    }
}
