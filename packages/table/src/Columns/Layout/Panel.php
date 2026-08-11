<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns\Layout;

final class Panel extends Component
{
    private bool $collapsible = false;

    private bool $collapsed = true;

    public function collapsible(bool $enabled = true): self
    {
        $this->collapsible = $enabled;

        return $this;
    }

    public function collapsed(bool $collapsed = true): self
    {
        $this->collapsed = $collapsed;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'collapsible' => $this->collapsible, 'collapsed' => $this->collapsed];
    }

    protected function type(): string
    {
        return 'panel-layout';
    }
}
