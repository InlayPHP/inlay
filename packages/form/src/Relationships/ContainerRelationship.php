<?php

declare(strict_types=1);

namespace Inlay\Forms\Relationships;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Inlay\Forms\Field;
use Inlay\Schemas\Component as SchemaComponent;

/**
 * Read and write the single related record a layout container is bound to.
 *
 * The Schemas kernel owns the relationship name and the state path it implies;
 * this class owns everything Eloquent, so schemas stay database-free.
 */
final readonly class ContainerRelationship
{
    private function __construct(
        private SchemaComponent $component,
        private string $name,
        private string $path,
    ) {}

    public static function make(SchemaComponent $component, string $path): self
    {
        $name = $component->getRelationship();
        if ($name === null) {
            throw new \LogicException("Schema container [{$path}] does not define a relationship.");
        }

        return new self($component, $name, $path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @param Model|class-string<Model> $owner */
    public function bind(Model|string $owner): Relation
    {
        $owner = is_string($owner) ? new $owner : $owner;
        if (! method_exists($owner, $this->name)) {
            throw new \LogicException("Relationship [{$this->name}] does not exist on [".$owner::class.'].');
        }

        $relation = $owner->{$this->name}();
        if (! $relation instanceof HasOne && ! $relation instanceof MorphOne && ! $relation instanceof BelongsTo) {
            throw new \LogicException("Schema container relationship [{$this->name}] must be an Eloquent HasOne, MorphOne, or BelongsTo relationship.");
        }

        return $relation;
    }

    /**
     * @param  Model|class-string<Model>  $owner
     * @return array<string, mixed>
     */
    public function state(Model|string $owner): array
    {
        $this->bind($owner);
        if (is_string($owner) || ! $owner->exists) {
            return [];
        }

        $related = $owner->{$this->name};

        return $related instanceof Model
            ? array_intersect_key($related->attributesToArray(), array_flip($this->fieldNames()))
            : [];
    }

    /** @param array<string, mixed> $state */
    public function save(Model $owner, mixed $state): void
    {
        if (! is_array($state)) {
            throw new \InvalidArgumentException("Schema container relationship [{$this->name}] state must be an array.");
        }

        $relation = $this->bind($owner);
        $attributes = array_intersect_key($state, array_flip($this->fieldNames()));

        if ($relation instanceof BelongsTo) {
            $related = $owner->{$this->name} ?? $relation->getRelated()->newInstance();
            $related->fill($attributes)->save();
            $relation->associate($related);
            $owner->save();

            return;
        }

        $existing = $owner->{$this->name};
        if ($existing instanceof Model) {
            $existing->fill($attributes)->save();

            return;
        }

        $relation->create($attributes);
    }

    /**
     * Only the container's own fields cross the boundary, so an unrelated key
     * in the payload can never reach the related model.
     *
     * @return list<string>
     */
    private function fieldNames(): array
    {
        $names = [];
        $visit = static function (array $components) use (&$visit, &$names): void {
            foreach ($components as $component) {
                if ($component instanceof Field) {
                    $names[] = $component->name();
                }
                $nested = $component->childComponents();
                if ($nested !== [] && $component->getRelationship() === null) {
                    $visit($nested);
                }
            }
        };
        $visit($this->component->childComponents());

        return array_values(array_unique($names));
    }
}
