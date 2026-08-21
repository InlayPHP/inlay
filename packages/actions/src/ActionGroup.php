<?php

declare(strict_types=1);

namespace Inlay\Actions;

use Closure;
use Inlay\Actions\Enums\ActionSize;
use Inlay\Actions\Enums\ActionTriggerStyle;
use Inlay\Actions\Enums\IconPosition;
use Inlay\Support\ClosureEvaluator;
use InvalidArgumentException;
use JsonSerializable;

final class ActionGroup implements JsonSerializable
{
    /** Optional renderer identity for repeated group mounts. */
    private ?string $instanceKey = null;

    private string|Closure|null $label = null;

    private string|Closure|null $icon = null;

    private string|Closure $color = 'default';

    private Action $trigger;

    /** Keep the default menu inside a table row's visible surface. */
    private string $dropdownPlacement = 'bottom-end';

    private string $dropdownWidth = 'sm';

    private bool $dropdown = true;

    private bool $buttonGroup = false;

    /** @param list<Action|ActionGroup> $actions */
    private function __construct(private readonly string $name, private array $actions)
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('An action group name cannot be empty.');
        }

        $this->actions($actions);
        $this->trigger = Action::make($name);
    }

    private function resolvePresentation(string|Closure|null $value, string $property): ?string
    {
        if ($value === null) {
            return null;
        }

        $resolved = $value instanceof Closure
            ? ClosureEvaluator::evaluate($value, ['group' => $this], [self::class => $this], [$this])
            : $value;

        if ($resolved === null) {
            return null;
        }
        if (! is_string($resolved) || trim($resolved) === '') {
            throw new \UnexpectedValueException("Action group [{$this->name}] {$property} must resolve to a non-empty string.");
        }

        return $resolved;
    }

    /**
     * @param  string|list<Action|ActionGroup>  $name
     * @param  list<Action|ActionGroup>|null  $actions
     */
    public static function make(string|array $name, ?array $actions = null): self
    {
        if (is_array($name)) {
            $actions = $name;
            $names = array_map(
                static fn (mixed $action): string => match (true) {
                    $action instanceof Action => $action->name(),
                    $action instanceof self => $action->name(),
                    default => get_debug_type($action),
                },
                $actions,
            );
            $name = 'group-'.substr(hash('sha256', implode('|', $names)), 0, 12);
        }

        if ($actions === null) {
            throw new InvalidArgumentException('An action group must contain an action list.');
        }

        return new self($name, $actions);
    }

    /** A closure resolves when the group is serialized, never in the browser. */
    public function label(string|Closure $label): self
    {
        if (is_string($label) && trim($label) === '') {
            throw new InvalidArgumentException('An action group label cannot be empty.');
        }

        $this->label = is_string($label) ? trim($label) : $label;

        return $this;
    }

    /** A closure resolves when the group is serialized, never in the browser. */
    public function icon(string|Closure $icon): self
    {
        if (is_string($icon) && trim($icon) === '') {
            throw new InvalidArgumentException('An action group icon cannot be empty.');
        }

        $this->icon = is_string($icon) ? trim($icon) : $icon;

        return $this;
    }

    /** A closure resolves when the group is serialized, never in the browser. */
    public function color(string|Closure $color): self
    {
        if (is_string($color) && trim($color) === '') {
            throw new InvalidArgumentException('An action group color cannot be empty.');
        }

        $this->color = is_string($color) ? trim($color) : $color;

        return $this;
    }

    public function iconPosition(IconPosition|string $position): self
    {
        $this->trigger->iconPosition($position);

        return $this;
    }

    public function size(ActionSize|string $size): self
    {
        $this->trigger->size($size);

        return $this;
    }

    public function tooltip(?string $tooltip): self
    {
        $this->trigger->tooltip($tooltip);

        return $this;
    }

    public function button(): self
    {
        $this->trigger->button();

        return $this;
    }

    public function link(): self
    {
        $this->trigger->link();

        return $this;
    }

    public function iconButton(): self
    {
        $this->trigger->iconButton();

        return $this;
    }

    public function triggerStyle(ActionTriggerStyle|string $style): self
    {
        $this->trigger->triggerStyle($style);

        return $this;
    }

    public function badge(string|int|null $badge = null): self
    {
        if (func_num_args() === 0) {
            $this->trigger->badge();
        } else {
            $this->trigger->badge($badge);
        }

        return $this;
    }

    public function badgeColor(string $color): self
    {
        $this->trigger->badgeColor($color);

        return $this;
    }

    public function outlined(bool $outlined = true): self
    {
        $this->trigger->outlined($outlined);

        return $this;
    }

    public function disabled(bool $disabled = true): self
    {
        $this->trigger->disabled($disabled);

        return $this;
    }

    /** @param string|list<string> $bindings */
    public function keyBindings(string|array $bindings): self
    {
        $this->trigger->keyBindings($bindings);

        return $this;
    }

    public function dropdownPlacement(string $placement): self
    {
        $placement = strtolower(trim($placement));
        if (! in_array($placement, ['top-start', 'top', 'top-end', 'bottom-start', 'bottom', 'bottom-end', 'left-start', 'left', 'left-end', 'right-start', 'right', 'right-end'], true)) {
            throw new InvalidArgumentException("Unsupported action group dropdown placement [{$placement}].");
        }

        $this->dropdownPlacement = $placement;

        return $this;
    }

    public function dropdownWidth(string $width): self
    {
        $width = strtolower(trim($width));
        if (! in_array($width, ['xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl', '7xl'], true)) {
            throw new InvalidArgumentException("Unsupported action group dropdown width [{$width}].");
        }

        $this->dropdownWidth = $width;

        return $this;
    }

    /**
     * Put a nested group's actions directly inside its parent menu.
     *
     * This API uses this form for labelled sections and visual dividers.
     */
    public function dropdown(bool $enabled = true): self
    {
        $this->dropdown = $enabled;

        return $this;
    }

    /**
     * Render this group's children as one compact, inline button group.
     *
     * Nested groups remain dropdown triggers inside the button group.
     */
    public function buttonGroup(bool $enabled = true): self
    {
        $this->buttonGroup = $enabled;

        return $this;
    }

    /** @param list<Action|ActionGroup> $actions */
    public function actions(array $actions): self
    {
        if ($actions === []) {
            throw new InvalidArgumentException('An action group must contain at least one action.');
        }

        foreach ($actions as $action) {
            if (! $action instanceof Action && ! $action instanceof self) {
                throw new InvalidArgumentException('Action groups may contain only actions or nested action groups.');
            }
        }

        $this->actions = array_values($actions);

        return $this;
    }

    /** @return list<Action|ActionGroup> */
    public function groupedActions(): array
    {
        return $this->actions;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Set a stable renderer identity without changing group lookup or the
     * names of the actions contained by this group.
     */
    public function instanceKey(string $key): self
    {
        $key = trim($key);

        if ($key === '' || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new InvalidArgumentException('An action group instance key must be a non-empty printable string.');
        }

        $this->instanceKey = $key;

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->serialize([]);
    }

    /**
     * @param  array<int, true>  $ancestors
     * @return array<string, mixed>
     */
    private function serialize(array $ancestors): array
    {
        $id = spl_object_id($this);
        if (isset($ancestors[$id])) {
            throw new \LogicException("Action group [{$this->name}] contains a recursive group reference.");
        }
        $ancestors[$id] = true;

        $icon = $this->resolvePresentation($this->icon, 'icon');
        $triggerAction = clone $this->trigger;
        if ($icon !== null) {
            $triggerAction->icon($icon);
        }
        $trigger = $triggerAction->jsonSerialize();

        return [
            'type' => 'action-group',
            'name' => $this->name,
            ...($this->instanceKey === null ? [] : ['instanceKey' => $this->instanceKey]),
            'label' => $this->resolvePresentation($this->label, 'label')
                ?? ucwords(str_replace(['_', '-'], ' ', $this->name)),
            'icon' => $icon,
            'color' => $this->resolvePresentation($this->color, 'color'),
            'iconPosition' => $trigger['iconPosition'],
            'size' => $trigger['size'],
            'triggerStyle' => $trigger['triggerStyle'],
            'tooltip' => $trigger['tooltip'],
            'badge' => $trigger['badge'],
            'badgeColor' => $trigger['badgeColor'],
            'outlined' => $trigger['outlined'],
            'disabled' => $trigger['disabled'],
            'keyBindings' => $trigger['keyBindings'],
            'dropdownPlacement' => $this->dropdownPlacement,
            'dropdownWidth' => $this->dropdownWidth,
            'dropdown' => $this->dropdown,
            'buttonGroup' => $this->buttonGroup,
            'actions' => array_map(
                static fn (Action|self $action): array => $action instanceof self
                    ? $action->serialize($ancestors)
                    : $action->jsonSerialize(),
                $this->actions,
            ),
        ];
    }
}
