<?php

declare(strict_types=1);

namespace Inlay;

use Inlay\Concerns\HasSafeAttributes;
use Inlay\Support\Condition;
use Inlay\Support\SafeUrl;
use InvalidArgumentException;
use JsonSerializable;

final class NavigationItem implements JsonSerializable
{
    use HasSafeAttributes;

    private ?string $label = null;

    private ?string $icon = null;

    private ?string $url = null;

    private string|int|null $badge = null;

    private ?string $group = null;

    private int $sort = 0;

    private bool $visible = true;

    private ?Condition $visibleWhen = null;

    /** An ability the visitor must hold for this item to be listed at all. */
    private ?string $ability = null;

    private bool $active = false;

    private ?Condition $activeWhen = null;

    private bool $openInNewTab = false;

    private function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A navigation item name cannot be empty.');
        }

        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function labelText(): string
    {
        return $this->label ?? self::headline($this->name);
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function url(string $url, bool $newTab = false): self
    {
        $this->url = SafeUrl::from($url)->value();
        $this->openInNewTab = $newTab;

        return $this;
    }

    public function badge(string|int|null $badge): self
    {
        $this->badge = $badge;

        return $this;
    }

    public function group(?string $group): self
    {
        $this->group = $group === null ? null : trim($group);

        return $this;
    }

    public function sort(int $sort): self
    {
        $this->sort = $sort;

        return $this;
    }

    public function sortOrder(): int
    {
        return $this->sort;
    }

    public function groupName(): ?string
    {
        return $this->group === '' ? null : $this->group;
    }

    public function visible(bool $visible = true): self
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * Hide this item unless the visitor holds the ability.
     *
     * Navigation that advertises screens the visitor cannot open is a dead end
     * at best and a map of the admin surface at worst, so the decision is made
     * on the server and the item never reaches the browser.
     */
    public function ability(string $ability): self
    {
        $this->ability = $ability;

        return $this;
    }

    public function requiredAbility(): ?string
    {
        return $this->ability;
    }

    public function visibleWhen(Condition|string $path, mixed $value = true, string $operator = 'equals'): self
    {
        $this->visibleWhen = $path instanceof Condition ? $path : Condition::make($path, $value, $operator);

        return $this;
    }

    public function active(bool $active = true): self
    {
        $this->active = $active;

        return $this;
    }

    public function activeWhen(Condition|string $path, mixed $value = true, string $operator = 'equals'): self
    {
        $this->activeWhen = $path instanceof Condition ? $path : Condition::make($path, $value, $operator);

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->labelText(),
            'icon' => $this->icon,
            'url' => $this->url,
            'badge' => $this->badge,
            'group' => $this->group,
            'sort' => $this->sort,
            'visible' => $this->visible,
            'visibleWhen' => $this->visibleWhen,
            'active' => $this->active,
            'activeWhen' => $this->activeWhen,
            'openInNewTab' => $this->openInNewTab,
            'extraAttributes' => (object) $this->serializedExtraAttributes(),
        ];
    }

    private static function headline(string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }
}
