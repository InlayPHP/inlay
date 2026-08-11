<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Closure;
use Inlay\Schemas\Component;
use Inlay\Schemas\Concerns\HasExtraActions;
use Inlay\Schemas\Concerns\HasSchema;

final class Tab extends Component
{
    use HasExtraActions;
    use HasSchema;

    private string|int|Closure|null $badge = null;

    private string|Closure $badgeColor = 'neutral';

    private string|Closure|null $icon = null;

    private string $iconPosition = 'before';

    protected function type(): string
    {
        return 'tab';
    }

    public function badge(string|int|Closure|null $badge): self
    {
        $this->badge = $badge;

        return $this;
    }

    public function badgeColor(string|Closure $color): self
    {
        $this->badgeColor = $color;

        return $this;
    }

    public function icon(string|Closure|null $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function iconPosition(string $position): self
    {
        if (! in_array($position, ['before', 'after'], true)) {
            throw new \InvalidArgumentException('Tab icon position must be [before] or [after].');
        }

        $this->iconPosition = $position;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            ...$this->serializedSchema(),
            ...$this->serializedExtraActions(),
            'badge' => $this->resolvePresentationScalar($this->badge, 'tab badge'),
            'badgeColor' => $this->resolvePresentationString($this->badgeColor, 'tab badge color', nullable: false),
            'icon' => $this->resolvePresentationString($this->icon, 'tab icon'),
            'iconPosition' => $this->iconPosition,
        ];
    }
}
