<?php

declare(strict_types=1);

namespace Inlay\Core;

use InvalidArgumentException;

final class ExtensionRegistry
{
    /** @var array<string, class-string> */
    private array $types = [];

    /** @var array<string, array<string, object>> */
    private array $extensions = [];

    /** @var array<string, array<string, string>> */
    private array $owners = [];

    /** @internal */
    public function checkpoint(): RegistryCheckpoint
    {
        $types = $this->types;
        $extensions = $this->extensions;
        $owners = $this->owners;

        return new RegistryCheckpoint(function () use ($types, $extensions, $owners): void {
            $this->types = $types;
            $this->extensions = $extensions;
            $this->owners = $owners;
        });
    }

    /** @param class-string $expectedClass */
    public function define(string $type, string $expectedClass): self
    {
        $this->assertName($type, 'extension type');

        if (! class_exists($expectedClass) && ! interface_exists($expectedClass)) {
            throw new InvalidArgumentException("Extension class or interface [{$expectedClass}] does not exist.");
        }

        if (isset($this->types[$type]) && $this->types[$type] !== $expectedClass) {
            throw new InvalidArgumentException("Extension type [{$type}] is already defined as [{$this->types[$type]}].");
        }

        $this->types[$type] = $expectedClass;

        return $this;
    }

    public function register(string $type, string $name, object $extension, string $owner): self
    {
        $this->assertName($name, 'extension name');
        $this->assertName($owner, 'extension owner');
        $expected = $this->types[$type] ?? throw new InvalidArgumentException("Extension type [{$type}] is not defined.");

        if (! $extension instanceof $expected) {
            throw new InvalidArgumentException("Extension [{$type}:{$name}] must be an instance of [{$expected}].");
        }

        if (isset($this->extensions[$type][$name])) {
            $existingOwner = $this->owners[$type][$name];
            throw new InvalidArgumentException("Extension [{$type}:{$name}] is already registered by [{$existingOwner}].");
        }

        $this->extensions[$type][$name] = $extension;
        $this->owners[$type][$name] = $owner;

        return $this;
    }

    public function get(string $type, string $name): object
    {
        return $this->extensions[$type][$name]
            ?? throw new InvalidArgumentException("Extension [{$type}:{$name}] is not registered.");
    }

    public function has(string $type, string $name): bool
    {
        return isset($this->extensions[$type][$name]);
    }

    /** @return array<string, object> */
    public function all(string $type): array
    {
        $extensions = $this->extensions[$type] ?? [];
        ksort($extensions);

        return $extensions;
    }

    public function owner(string $type, string $name): string
    {
        return $this->owners[$type][$name]
            ?? throw new InvalidArgumentException("Extension [{$type}:{$name}] is not registered.");
    }

    private function assertName(string $value, string $description): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("An {$description} cannot be empty.");
        }
    }
}
