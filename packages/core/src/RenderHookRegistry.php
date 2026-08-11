<?php

declare(strict_types=1);

namespace Inlay\Core;

use InvalidArgumentException;

final class RenderHookRegistry
{
    /** @var array<string, array<string, array{hook: RenderHook, owner: string, order: int}>> */
    private array $hooks = [];

    private int $nextOrder = 0;

    /** @internal */
    public function checkpoint(): RegistryCheckpoint
    {
        $hooks = $this->hooks;
        $nextOrder = $this->nextOrder;

        return new RegistryCheckpoint(function () use ($hooks, $nextOrder): void {
            $this->hooks = $hooks;
            $this->nextOrder = $nextOrder;
        });
    }

    public function register(RenderHook $hook, string $owner): self
    {
        if (trim($owner) === '') {
            throw new InvalidArgumentException('A render hook owner cannot be empty.');
        }

        if (isset($this->hooks[$hook->name][$hook->id])) {
            $existingOwner = $this->hooks[$hook->name][$hook->id]['owner'];
            throw new InvalidArgumentException("Render hook [{$hook->name}:{$hook->id}] is already registered by [{$existingOwner}].");
        }

        $this->hooks[$hook->name][$hook->id] = [
            'hook' => $hook,
            'owner' => $owner,
            'order' => $this->nextOrder++,
        ];

        return $this;
    }

    /** @return list<RenderHook> */
    public function all(string $name): array
    {
        $entries = array_values($this->hooks[$name] ?? []);
        usort($entries, static fn (array $left, array $right): int =>
            ($right['hook']->priority <=> $left['hook']->priority) ?: ($left['order'] <=> $right['order'])
        );

        return array_map(static fn (array $entry): RenderHook => $entry['hook'], $entries);
    }

    /** @param array<string, mixed> $context */
    public function render(string $name, array $context = []): string
    {
        return implode('', array_map(
            static fn (RenderHook $hook): string => $hook->render($context),
            $this->all($name),
        ));
    }
}
