<?php

declare(strict_types=1);

namespace Inlay\Authorization;

use InvalidArgumentException;

final class AbilityRegistry
{
    /** @var array<string, AbilityDefinition> */
    private array $abilities = [];

    /** @var array<string, string> */
    private array $owners = [];

    public function register(AbilityDefinition $ability, string $owner): self
    {
        $owner = trim($owner);
        if ($owner === '') {
            throw new InvalidArgumentException('An ability owner cannot be empty.');
        }

        if (isset($this->abilities[$ability->name()])) {
            if ($this->owners[$ability->name()] === $owner) {
                return $this;
            }

            throw new InvalidArgumentException("Ability [{$ability->name()}] is already registered by [{$this->owners[$ability->name()]}].");
        }

        $this->abilities[$ability->name()] = $ability;
        $this->owners[$ability->name()] = $owner;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->abilities[$name]);
    }

    public function get(string $name): AbilityDefinition
    {
        return $this->abilities[$name] ?? throw new InvalidArgumentException("Ability [{$name}] is not registered.");
    }

    /** @return array<string, AbilityDefinition> */
    public function all(): array
    {
        $abilities = $this->abilities;
        ksort($abilities);

        return $abilities;
    }

    public function owner(string $name): string
    {
        return $this->owners[$name] ?? throw new InvalidArgumentException("Ability [{$name}] is not registered.");
    }
}
