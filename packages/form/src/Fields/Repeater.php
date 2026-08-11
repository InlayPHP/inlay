<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Inlay\Forms\Field;
use Inlay\Forms\Form;
use Inlay\Forms\Repeater\TableColumn;
use Inlay\Schemas\Concerns\HasSchema;

class Repeater extends Field
{
    use HasSchema;

    private int $minItems = 0;

    private ?int $maxItems = null;

    private string $addActionLabel = 'Add item';

    private bool $reorderable = true;

    private bool $collapsible = false;

    private bool $cloneable = false;

    private string $relatedKeyName = 'id';

    private ?string $relationship = null;

    private bool $deleteMissingRelatedRecords = true;

    /** @var list<TableColumn> */
    private array $tableColumns = [];

    protected function type(): string
    {
        return 'repeater';
    }

    /**
     * Render items as table rows under a shared header instead of stacked
     * cards. Columns line up positionally with this repeater's child fields.
     *
     * @param  list<TableColumn>  $columns
     */
    public function table(array $columns): static
    {
        foreach ($columns as $column) {
            if (! $column instanceof TableColumn) {
                throw new \InvalidArgumentException("Repeater [{$this->name}] table columns must be ".TableColumn::class.' instances.');
            }
        }

        if ($columns === []) {
            throw new \InvalidArgumentException("Repeater [{$this->name}] table layouts need at least one column.");
        }

        $this->tableColumns = array_values($columns);

        return $this;
    }

    /** @return list<TableColumn> */
    public function tableColumnDefinitions(): array
    {
        return $this->tableColumns;
    }

    public function minItems(int $count): static
    {
        $this->minItems = $count;

        return $this;
    }

    public function maxItems(int $count): static
    {
        $this->maxItems = $count;

        return $this;
    }

    public function addActionLabel(string $label): static
    {
        $this->addActionLabel = $label;

        return $this;
    }

    public function reorderable(bool $enabled = true): static
    {
        $this->reorderable = $enabled;

        return $this;
    }

    public function collapsible(bool $enabled = true): static
    {
        $this->collapsible = $enabled;

        return $this;
    }

    public function cloneable(bool $enabled = true): static
    {
        $this->cloneable = $enabled;

        return $this;
    }

    public function relationship(?string $name = null): static
    {
        $name ??= $this->name();
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Repeater relationship names must be valid PHP method names.');
        }
        $this->relationship = $name;

        return $this;
    }

    public function deleteMissingRelatedRecords(bool $enabled = true): static
    {
        $this->deleteMissingRelatedRecords = $enabled;

        return $this;
    }

    public function managesRelationshipPersistence(): bool
    {
        return $this->relationship !== null;
    }

    /** @param Model|class-string<Model> $owner */
    public function bindRelationship(Model|string $owner): HasMany|MorphMany
    {
        if ($this->relationship === null) {
            throw new \LogicException("Repeater [{$this->name()}] does not define a relationship.");
        }
        $owner = is_string($owner) ? new $owner : $owner;
        if (! method_exists($owner, $this->relationship)) {
            throw new \LogicException("Relationship [{$this->relationship}] does not exist on [".$owner::class.'].');
        }
        $relation = $owner->{$this->relationship}();
        if (! $relation instanceof HasMany && ! $relation instanceof MorphMany) {
            throw new \LogicException("Repeater relationship [{$this->relationship}] must be an Eloquent HasMany or MorphMany relationship.");
        }
        $this->relatedKeyName = $relation->getRelated()->getKeyName();

        return $relation;
    }

    /** @param Model|class-string<Model> $owner @return list<array<string, mixed>> */
    public function relationshipState(Model|string $owner): array
    {
        $relation = $this->bindRelationship($owner);
        if (is_string($owner) || ! $owner->exists) {
            return [];
        }

        return $relation->get()->map(function (Model $record): array {
            $payload = $this->rowForm($record)->data($record->attributesToArray())->jsonSerialize();

            return (array) $payload['data'];
        })->values()->all();
    }

    public function saveRelationship(Model $owner, mixed $state): void
    {
        if (! is_array($state)) {
            throw new \InvalidArgumentException("Relationship repeater [{$this->name()}] state must be an array.");
        }
        $relation = $this->bindRelationship($owner);
        $related = $relation->getRelated();
        $keyName = $related->getKeyName();
        $existing = $relation->get()->keyBy(fn (Model $record): string => (string) $record->getKey());
        $kept = [];

        foreach (array_values($state) as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException("Relationship repeater [{$this->name()}] items must be objects.");
            }
            $key = $item[$keyName] ?? null;
            unset($item[$keyName]);
            if ($key !== null && $key !== '') {
                $record = $existing->get((string) $key);
                if (! $record instanceof Model) {
                    throw new \InvalidArgumentException("Related record [{$key}] does not belong to relationship [{$this->relationship}].");
                }
                $persistence = $this->rowForm($record)->splitRelationshipData($item);
                $record->fill($persistence['attributes'])->save();
                $this->rowForm($record)->saveRelationships($record, $persistence['relationships']);
                $kept[] = (string) $record->getKey();

                continue;
            }
            $rowForm = $this->rowForm($related::class);
            $persistence = $rowForm->splitRelationshipData($item);
            $record = $relation->create($persistence['attributes']);
            $this->rowForm($record)->saveRelationships($record, $persistence['relationships']);
            $kept[] = (string) $record->getKey();
        }

        if ($this->deleteMissingRelatedRecords) {
            $existing->reject(fn (Model $record, int|string $key): bool => in_array((string) $key, $kept, true))
                ->each(fn (Model $record): ?bool => $record->delete());
        }
    }

    /** @param Model|class-string<Model> $record */
    public function rowForm(Model|string $record): Form
    {
        return Form::make($this->name().'-row')->model($record)->schema($this->getSchema());
    }

    /** @param Model|class-string<Model> $owner @return class-string<Model> */
    public function relatedModel(Model|string $owner): string
    {
        return $this->bindRelationship($owner)->getRelated()::class;
    }

    /** @param Model|class-string<Model> $owner */
    public function relatedKeyName(Model|string $owner): string
    {
        return $this->bindRelationship($owner)->getRelated()->getKeyName();
    }

    /** @param Model|class-string<Model> $owner */
    public function ownsRelatedKey(Model|string $owner, mixed $key): bool
    {
        if (is_string($owner) || ! $owner->exists || (! is_string($key) && ! is_int($key))) {
            return false;
        }

        return $this->bindRelationship($owner)->whereKey($key)->exists();
    }

    /** @param Model|class-string<Model> $owner */
    public function relatedRecord(Model|string $owner, mixed $key): ?Model
    {
        if (is_string($owner) || ! $owner->exists || (! is_string($key) && ! is_int($key))) {
            return null;
        }

        return $this->bindRelationship($owner)->whereKey($key)->first();
    }

    /**
     * A table layout describes every cell in its header, so the column count
     * must match the child fields it labels.
     *
     * @return array<string, mixed>|null
     */
    private function serializedTable(): ?array
    {
        if ($this->tableColumns === []) {
            return null;
        }

        $fields = array_values(array_filter(
            $this->childComponents(),
            static fn (object $component): bool => $component instanceof Field,
        ));

        if (count($fields) !== count($this->tableColumns)) {
            throw new \LogicException(
                "Repeater [{$this->name}] declares ".count($this->tableColumns).' table column(s) for '.count($fields).' field(s).',
            );
        }

        return ['columns' => $this->tableColumns];
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'table' => $this->serializedTable(), ...$this->serializedSchema(), 'minItems' => $this->minItems, 'maxItems' => $this->maxItems, 'addActionLabel' => $this->addActionLabel, 'reorderable' => $this->reorderable, 'collapsible' => $this->collapsible, 'cloneable' => $this->cloneable, 'relationship' => $this->relationship === null ? null : ['name' => $this->relationship, 'type' => 'hasMany', 'keyName' => $this->relatedKeyName], 'deletesMissingRelatedRecords' => $this->deleteMissingRelatedRecords];
    }
}
