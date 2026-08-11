<?php

declare(strict_types=1);

namespace Inlay\Schemas\Testing;

use Closure;
use Inlay\Schemas\Component;
use Inlay\Schemas\Contracts\ProvidesSchema;
use Inlay\Schemas\Schema;
use Inlay\Support\Testing\Assertions;

/**
 * Drive a schema through its real context and assert on the resolved tree.
 *
 * The tester never re-implements resolution: it changes the operation, record,
 * or state and then reads the same components, paths, and payload a renderer
 * would receive.
 */
final class SchemaTester
{
    private function __construct(private readonly Schema $schema) {}

    /** @param Schema|ProvidesSchema|list<mixed> $schema */
    public static function make(Schema|ProvidesSchema|array $schema, string $name = 'schema'): self
    {
        if ($schema instanceof Schema) {
            return new self($schema);
        }

        return new self(Schema::make($name)->components(is_array($schema) ? $schema : [$schema]));
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    /** @param array<string, mixed> $state */
    public function fillState(array $state): self
    {
        $this->schema->state(array_replace_recursive($this->schema->getContext()->state, $state));

        return $this;
    }

    public function operation(string $operation): self
    {
        $this->schema->operation($operation);

        return $this;
    }

    public function record(mixed $record): self
    {
        $this->schema->record($record);

        return $this;
    }

    /** @param Closure(Component): bool|null $check */
    public function assertComponentExists(string $key, ?Closure $check = null): self
    {
        $component = $this->component($key);
        Assertions::true($component instanceof Component, "Expected schema component [{$key}] to exist.");
        if ($check !== null) {
            Assertions::true(
                $check($component) === true,
                "Schema component [{$key}] exists, but its configuration assertion failed.",
            );
        }

        return $this;
    }

    public function assertComponentMissing(string $key): self
    {
        Assertions::true(
            $this->schema->getComponent($key) === null,
            "Expected schema component [{$key}] to be missing.",
        );

        return $this;
    }

    public function assertComponentVisible(string $key): self
    {
        Assertions::true(
            $this->required($key)->isHiddenForState($this->schema->getContext()) === false,
            "Expected schema component [{$key}] to be visible for the current state.",
        );

        return $this;
    }

    public function assertComponentHidden(string $key): self
    {
        Assertions::true(
            $this->required($key)->isHiddenForState($this->schema->getContext()),
            "Expected schema component [{$key}] to be hidden for the current state.",
        );

        return $this;
    }

    public function assertStatePath(string $key, string $expected): self
    {
        Assertions::same(
            $expected,
            $this->required($key)->getStatePath(),
            "Schema component [{$key}] resolved an unexpected state path.",
        );

        return $this;
    }

    public function assertState(string $path, mixed $expected): self
    {
        Assertions::same(
            $expected,
            $this->schema->getContext()->get($path),
            "Schema state [{$path}] did not match the expected value.",
        );

        return $this;
    }

    /** @param list<string> $names */
    public function assertComponentOrder(array $names, ?string $parent = null): self
    {
        $components = $parent === null
            ? $this->schema->getComponents()
            : $this->required($parent)->childComponents();

        Assertions::same(
            $names,
            array_map(static fn (Component $component): string => $component->name(), $components),
            $parent === null
                ? 'The schema resolved an unexpected root component order.'
                : "Schema component [{$parent}] resolved an unexpected child order.",
        );

        return $this;
    }

    /** @param int|array<string, int> $expected */
    public function assertColumns(int|array $expected): self
    {
        Assertions::same($expected, $this->schema->getColumns(), 'The schema resolved unexpected columns.');

        return $this;
    }

    /** @param list<string> $names */
    public function assertHeaderSchema(string $key, array $names): self
    {
        return $this->assertSlot($key, 'header', $names);
    }

    /** @param list<string> $names */
    public function assertFooterSchema(string $key, array $names): self
    {
        return $this->assertSlot($key, 'footer', $names);
    }

    /** @param list<string> $names */
    public function assertHeaderActions(string $key, array $names): self
    {
        return $this->assertActionSlot($key, 'header', $names);
    }

    /** @param list<string> $names */
    public function assertFooterActions(string $key, array $names): self
    {
        return $this->assertActionSlot($key, 'footer', $names);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return json_decode(
            json_encode($this->schema->jsonSerialize(), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function component(string $key): ?Component
    {
        return $this->schema->getComponent($key);
    }

    private function required(string $key): Component
    {
        $component = $this->component($key);
        if (! $component instanceof Component) {
            Assertions::fail("Expected schema component [{$key}] to exist.");
        }

        return $component;
    }

    /** @param list<string> $names */
    private function assertSlot(string $key, string $slot, array $names): self
    {
        $component = $this->required($key);
        $method = $slot === 'header' ? 'getHeaderSchema' : 'getFooterSchema';
        Assertions::true(
            method_exists($component, $method),
            "Schema component [{$key}] does not support named {$slot} schema slots.",
        );

        Assertions::same(
            $names,
            array_map(static fn (Component $slotComponent): string => $slotComponent->name(), $component->{$method}()),
            "Schema component [{$key}] resolved an unexpected {$slot} schema slot.",
        );

        return $this;
    }

    /** @param list<string> $names */
    private function assertActionSlot(string $key, string $slot, array $names): self
    {
        $payload = $this->componentPayload($key);
        $actions = $payload[$slot.'Actions'] ?? null;
        Assertions::true(
            is_array($actions),
            "Schema component [{$key}] does not support named {$slot} action slots.",
        );

        Assertions::same(
            $names,
            array_map(static fn (array $action): string => (string) $action['name'], $actions),
            "Schema component [{$key}] resolved an unexpected {$slot} action slot.",
        );

        return $this;
    }

    /** @return array<string, mixed> */
    private function componentPayload(string $key): array
    {
        return json_decode(
            json_encode($this->required($key)->jsonSerialize(), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
