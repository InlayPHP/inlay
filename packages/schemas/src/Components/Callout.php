<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Inlay\Schemas\Support\ActionAlignment;
use Closure;
use Inlay\Schemas\Component;
use Inlay\Schemas\Support\SemanticColor;
use Inlay\Schemas\Concerns\HasExtraActions;
use Inlay\Schemas\Concerns\HasSchema;
use Inlay\Schemas\Concerns\HasSchemaSlots;

final class Callout extends Component
{
    use HasExtraActions;
    use HasSchema;
    use HasSchemaSlots;

    private string|Closure|null $description = null;

    private string|Closure $color = 'info';

    private string|Closure|null $icon = null;

    private string|Closure|null $iconColor = null;

    private string $iconSize = 'medium';

    private bool|Closure $background = true;

    private string|Closure|null $backgroundColor = null;

    private string $footerAlignment = 'start';

    protected function type(): string
    {
        return 'callout';
    }

    public function description(string|Closure|null $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function color(string|Closure $color): self
    {
        if (is_string($color)) {
            $this->validateColor($color);
        }
        $this->color = $color;

        return $this;
    }

    public function status(string $status): self
    {
        $this->color($status);
        $this->icon ??= match ($status) {
            'success' => 'check-circle',
            'warning' => 'exclamation-triangle',
            'danger' => 'x-circle',
            default => 'information-circle',
        };

        return $this;
    }

    public function icon(string|Closure|null $icon): self
    {
        if (is_string($icon) && trim($icon) === '') {
            throw new \InvalidArgumentException('Callout icon names cannot be empty.');
        }
        $this->icon = $icon;

        return $this;
    }

    public function iconColor(string|Closure|null $color): self
    {
        if (is_string($color)) {
            $this->validateColor($color);
        }
        $this->iconColor = $color;

        return $this;
    }

    public function iconSize(string $size): self
    {
        if (! in_array($size, ['small', 'medium', 'large'], true)) {
            throw new \InvalidArgumentException("Unsupported callout icon size [{$size}].");
        }
        $this->iconSize = $size;

        return $this;
    }

    public function background(bool|Closure $enabled = true): self
    {
        $this->background = $enabled;

        return $this;
    }

    public function backgroundColor(string|Closure|null $color): self
    {
        if (is_string($color)) {
            $this->validateColor($color);
        }
        $this->backgroundColor = $color;

        return $this;
    }

    /**
     * The callout's footer row is its action row, so this is the same setting the
     * shared `footerActionsAlignment()` carries. It delegates rather than holding a
     * second value: `HasExtraActions` now serializes an alignment for every
     * component that renders an action row, and two keys meaning one thing is how
     * renderers end up reading different ones.
     */
    public function footerAlignment(string $alignment): self
    {
        ActionAlignment::assert($alignment, 'callout footer alignment');
        $this->footerActionsAlignment($alignment);
        $this->footerAlignment = $alignment;

        return $this;
    }

    public function jsonSerialize(): array
    {
        $color = $this->resolvePresentationString($this->color, 'callout color', nullable: false);
        $iconColor = $this->resolvePresentationString($this->iconColor, 'callout icon color');
        $backgroundColor = $this->resolvePresentationString($this->backgroundColor, 'callout background color');
        $this->validateResolvedColor($color, 'color');
        if ($iconColor !== null) {
            $this->validateResolvedColor($iconColor, 'icon color');
        }
        if ($backgroundColor !== null) {
            $this->validateResolvedColor($backgroundColor, 'background color');
        }

        return [
            ...parent::jsonSerialize(),
            ...$this->serializedSchema(),
            ...$this->serializedSchemaSlots(),
            ...$this->serializedExtraActions(),
            'description' => $this->resolvePresentationString($this->description, 'callout description'),
            'color' => $color,
            'icon' => $this->resolvePresentationString($this->icon, 'callout icon'),
            'iconColor' => $iconColor,
            'iconSize' => $this->iconSize,
            'background' => $this->resolvePresentationBoolean($this->background, 'callout background'),
            'backgroundColor' => $backgroundColor,
            'footerAlignment' => $this->footerAlignment,
        ];
    }

    private function validateColor(string $color): void
    {
        SemanticColor::assert($color, 'callout color');
    }

    private function validateResolvedColor(string $color, string $property): void
    {
        SemanticColor::assert($color, "resolved callout {$property}", \UnexpectedValueException::class);
    }
}
