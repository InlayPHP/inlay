<?php

declare(strict_types=1);

namespace Inlay;

use Inlay\Contracts\PanelUser;
use InvalidArgumentException;
use LogicException;

final class PanelRegistry
{
    /** @var array<string, Panel> */
    private array $panels = [];

    /** @var array<string, string> */
    private array $paths = [];

    private ?string $defaultId = null;

    private bool $hasExplicitDefault = false;

    private ?string $currentId = null;

    public function register(Panel $panel, bool $default = false): self
    {
        if (isset($this->panels[$panel->id()])) {
            throw new InvalidArgumentException("A panel with ID [{$panel->id()}] is already registered.");
        }

        if (isset($this->paths[$panel->pathValue()])) {
            throw new InvalidArgumentException("A panel with path [{$panel->pathValue()}] is already registered.");
        }

        if ($default && $this->hasExplicitDefault) {
            throw new InvalidArgumentException("The default panel is already [{$this->defaultId}].");
        }

        $this->panels[$panel->id()] = $panel;
        $this->paths[$panel->pathValue()] = $panel->id();
        $this->defaultId ??= $panel->id();

        if ($default) {
            $this->defaultId = $panel->id();
            $this->hasExplicitDefault = true;
        }

        return $this;
    }

    public function get(string $id): Panel
    {
        return $this->panels[$id] ?? throw new InvalidArgumentException("Panel [{$id}] is not registered.");
    }

    public function findByPath(string $path): ?Panel
    {
        $path = $path === '/' ? '/' : '/'.trim($path, '/');
        $id = $this->paths[$path] ?? null;

        return $id === null ? null : $this->panels[$id];
    }

    public function default(): Panel
    {
        if ($this->defaultId === null) {
            throw new LogicException('No panels have been registered.');
        }

        return $this->panels[$this->defaultId];
    }

    public function setCurrent(Panel|string|null $panel): self
    {
        if ($panel === null) {
            $this->currentId = null;

            return $this;
        }

        $id = $panel instanceof Panel ? $panel->id() : $panel;
        $this->get($id);
        $this->currentId = $id;

        return $this;
    }

    public function current(): Panel
    {
        return $this->currentId === null ? $this->default() : $this->get($this->currentId);
    }

    /** @return list<Panel> */
    public function all(): array
    {
        $panels = array_values($this->panels);
        usort($panels, fn (Panel $left, Panel $right): int => $left->id() <=> $right->id());

        return $panels;
    }

    /**
     * Resolve the panels a visitor may enter.
     *
     * A model implementing PanelUser owns the decision. Models that do not
     * implement the optional contract preserve Panels' default behaviour and
     * may enter every registered panel. Guests receive no directory entries.
     *
     * @return list<Panel>
     */
    public function accessibleTo(mixed $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            $this->all(),
            static fn (Panel $panel): bool => ! $user instanceof PanelUser || $user->canAccessPanel($panel),
        ));
    }

    /**
     * Serialize the minimal panel directory contract for an authenticated UI.
     *
     * @return list<array{id: string, label: string, path: string, brandLogo: string|null}>
     */
    public function directoryFor(mixed $user): array
    {
        return array_map(
            static fn (Panel $panel): array => $panel->directoryEntry(),
            $this->accessibleTo($user),
        );
    }
}
