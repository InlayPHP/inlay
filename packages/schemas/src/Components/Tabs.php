<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Closure;
use Inlay\Schemas\Component;
use Inlay\Schemas\Concerns\HasExtraActions;
use Inlay\Schemas\Concerns\HasSchema;

final class Tabs extends Component
{
    use HasExtraActions;
    use HasSchema;

    private int $activeTab = 1;

    private bool $vertical = false;

    private bool $contained = true;

    private bool $scrollable = true;

    private bool $persistTab = false;

    private ?string $id = null;

    private ?string $queryStringKey = null;

    protected function type(): string
    {
        return 'tabs';
    }

    /** @param list<Component>|Closure $tabs */
    public function tabs(array|Closure $tabs): self
    {
        return $this->schema($tabs);
    }

    public function activeTab(int $tab): self
    {
        if ($tab < 1) {
            throw new \InvalidArgumentException('Active tab must be at least 1.');
        }

        $this->activeTab = $tab;

        return $this;
    }

    public function vertical(bool $enabled = true): self
    {
        $this->vertical = $enabled;

        return $this;
    }

    public function contained(bool $enabled = true): self
    {
        $this->contained = $enabled;

        return $this;
    }

    public function scrollable(bool $enabled = true): self
    {
        $this->scrollable = $enabled;

        return $this;
    }

    public function persistTab(bool $enabled = true): self
    {
        $this->persistTab = $enabled;

        return $this;
    }

    public function id(string $id): self
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $id)) {
            throw new \InvalidArgumentException('Tabs ID must start with a letter and contain only letters, numbers, underscores, and dashes.');
        }

        $this->id = $id;

        return $this;
    }

    public function persistTabInQueryString(string $key = 'tab'): self
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $key)) {
            throw new \InvalidArgumentException('Tabs query-string key is invalid.');
        }

        $this->queryStringKey = $key;

        return $this;
    }

    public function jsonSerialize(): array
    {
        if ($this->persistTab && $this->id === null) {
            throw new \LogicException('Persisted tabs require a unique ID.');
        }

        return [
            ...parent::jsonSerialize(),
            ...$this->serializedExtraActions(),
            'tabs' => $this->getSchema(),
            'activeTab' => $this->activeTab,
            'vertical' => $this->vertical,
            'contained' => $this->contained,
            'scrollable' => $this->scrollable,
            'persistTab' => $this->persistTab,
            'id' => $this->id,
            'queryStringKey' => $this->queryStringKey,
        ];
    }
}
