<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Inlay\Forms\Field;
use Inlay\Forms\Fields\MorphToSelect\Type;

final class MorphToSelect extends Field
{
    /** @var array<string, Type> */
    private array $types = [];

    private ?string $relationship = null;

    private bool $searchable = false;

    private bool $preload = false;

    private int $searchDebounce = 500;

    private ?string $remoteOptionsEndpoint = null;

    /** @var array{type?: string, id?: string|int}|null */
    private ?array $selectedState = null;

    protected function type(): string
    {
        return 'morph-to-select';
    }

    /** @param list<Type> $types */
    public function types(array $types): self
    {
        $mapped = [];
        foreach ($types as $type) {
            if (! $type instanceof Type) {
                throw new \InvalidArgumentException('MorphToSelect types must be MorphToSelect\Type instances.');
            }
            if (isset($mapped[$type->wireAlias()])) {
                throw new \InvalidArgumentException("Duplicate MorphTo type alias [{$type->wireAlias()}].");
            }
            $mapped[$type->wireAlias()] = $type;
        }
        if ($mapped === []) {
            throw new \InvalidArgumentException('MorphToSelect requires at least one allowed type.');
        }
        $this->types = $mapped;

        return $this;
    }

    public function relationship(?string $name = null): self
    {
        $name ??= $this->name();
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('MorphTo relationship names must be valid PHP method names.');
        }
        $this->relationship = $name;

        return $this;
    }

    public function searchable(bool $enabled = true): self
    {
        $this->searchable = $enabled;

        return $this;
    }

    public function preload(bool $enabled = true): self
    {
        $this->preload = $enabled;

        return $this;
    }

    public function searchDebounce(int $milliseconds): self
    {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException('MorphTo search debounce cannot be negative.');
        }
        $this->searchDebounce = $milliseconds;

        return $this;
    }

    public function configureRemoteOptions(?string $endpoint, mixed $state): void
    {
        $this->remoteOptionsEndpoint = $endpoint;
        $this->selectedState = is_array($state) ? $state : null;
    }

    /** @return list<array{value: string|int, label: string}> */
    public function searchOptions(string $alias, string $search): array
    {
        if (! $this->searchable) {
            throw new \LogicException("MorphToSelect [{$this->name()}] is not searchable.");
        }
        $type = $this->types[$alias] ?? null;
        if ($type === null) {
            throw new \InvalidArgumentException("Unknown MorphTo type [{$alias}].");
        }

        return $type->searchOptions($search);
    }

    /** @param Model|class-string<Model> $owner */
    public function bindRelationship(Model|string $owner): MorphTo
    {
        if ($this->types === []) {
            throw new \LogicException("MorphToSelect [{$this->name()}] requires types().");
        }
        $owner = is_string($owner) ? new $owner : $owner;
        $name = $this->relationship ?? $this->name();
        if (! method_exists($owner, $name)) {
            throw new \LogicException("Relationship [{$name}] does not exist on [".$owner::class.'].');
        }
        $relation = $owner->{$name}();
        if (! $relation instanceof MorphTo) {
            throw new \LogicException("MorphToSelect relationship [{$name}] must be an Eloquent MorphTo relationship.");
        }

        return $relation;
    }

    /** @param Model|class-string<Model> $owner @return array{type: string, id: string|int}|null */
    public function relationshipState(Model|string $owner): ?array
    {
        $relation = $this->bindRelationship($owner);
        if (is_string($owner) || ! $owner->exists) {
            return null;
        }
        $typeValue = $owner->getAttribute($relation->getMorphType());
        $key = $owner->getAttribute($relation->getForeignKeyName());
        if ($typeValue === null || $key === null) {
            return null;
        }
        foreach ($this->types as $type) {
            $model = new ($type->model());
            if (in_array((string) $typeValue, [(string) $type->wireAlias(), (string) $model->getMorphClass(), $type->model()], true)) {
                return ['type' => $type->wireAlias(), 'id' => $key];
            }
        }

        return null;
    }

    public function hasValidSelection(mixed $state): bool
    {
        if ($state === null || $state === '' || $state === []) {
            return ! $this->required;
        }
        if (! is_array($state) || ! is_string($state['type'] ?? null) || ! isset($state['id']) || (! is_string($state['id']) && ! is_int($state['id']))) {
            return false;
        }
        $type = $this->types[$state['type']] ?? null;

        return $type !== null && $type->resolve($state['id']) !== null;
    }

    /** @param Model|class-string<Model> $owner @return array<string, mixed> */
    public function relationshipAttributes(Model|string $owner, mixed $state): array
    {
        $relation = $this->bindRelationship($owner);
        if ($state === null || $state === '' || $state === []) {
            if ($this->required) {
                throw new \InvalidArgumentException("MorphToSelect [{$this->name()}] requires a selection.");
            }

            return [$relation->getMorphType() => null, $relation->getForeignKeyName() => null];
        }
        if (! $this->hasValidSelection($state)) {
            throw new \InvalidArgumentException("MorphToSelect [{$this->name()}] contains an invalid type or record.");
        }
        /** @var array{type: string, id: string|int} $state */
        $type = $this->types[$state['type']];
        $record = $type->resolve($state['id']);

        return [$relation->getMorphType() => $record?->getMorphClass(), $relation->getForeignKeyName() => $record?->getKey()];
    }

    public function jsonSerialize(): array
    {
        $types = array_map(function (Type $type): array {
            if (! $this->searchable) {
                return $type->jsonSerialize();
            }
            $options = $this->preload ? $type->searchOptions('') : [];
            if (($this->selectedState['type'] ?? null) === $type->wireAlias() && isset($this->selectedState['id'])) {
                $selected = $type->selectedOption($this->selectedState['id']);
                if ($selected !== null && ! in_array((string) $selected['value'], array_map(static fn (array $option): string => (string) $option['value'], $options), true)) {
                    $options[] = $selected;
                }
            }

            return $type->serializeWithOptions($options);
        }, array_values($this->types));

        return [...parent::jsonSerialize(), 'relationship' => ['name' => $this->relationship ?? $this->name(), 'type' => 'morphTo'], 'types' => $types, 'morphRemoteOptions' => $this->searchable ? ['endpoint' => $this->remoteOptionsEndpoint, 'preload' => $this->preload, 'searchDebounce' => $this->searchDebounce] : null];
    }
}
