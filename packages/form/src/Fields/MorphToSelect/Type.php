<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields\MorphToSelect;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

final class Type implements JsonSerializable
{
    private string $titleAttribute = 'name';

    private ?string $label = null;

    private ?string $alias = null;

    private int $optionsLimit = 50;

    private ?Closure $modifyQueryUsing = null;

    /** @var array<string|int, string>|null */
    private ?array $staticOptions = null;

    /** @param class-string<Model> $model */
    private function __construct(private readonly string $model)
    {
        if (! is_subclass_of($model, Model::class)) {
            throw new \InvalidArgumentException("MorphTo type [{$model}] must be an Eloquent model class.");
        }
    }

    /** @param class-string<Model> $model */
    public static function make(string $model): self
    {
        return new self($model);
    }

    public function titleAttribute(string $attribute): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $attribute) !== 1) {
            throw new \InvalidArgumentException('MorphTo title attributes must be plain column names.');
        }
        $this->titleAttribute = $attribute;

        return $this;
    }

    public function label(string $label): self
    {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('MorphTo type labels cannot be empty.');
        }
        $this->label = trim($label);

        return $this;
    }

    public function alias(string $alias): self
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('MorphTo aliases must contain only letters, numbers, dashes, and underscores.');
        }
        $this->alias = $alias;

        return $this;
    }

    public function optionsLimit(int $limit): self
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('MorphTo options limits must be between 1 and 500.');
        }
        $this->optionsLimit = $limit;

        return $this;
    }

    public function modifyOptionsQueryUsing(Closure $callback): self
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    /** @param array<string|int, string> $options */
    public function optionsUsing(array $options): self
    {
        $this->staticOptions = $options;

        return $this;
    }

    /** @return class-string<Model> */
    public function model(): string
    {
        return $this->model;
    }

    public function wireAlias(): string
    {
        return $this->alias ?? (new $this->model)->getMorphClass();
    }

    public function displayLabel(): string
    {
        if ($this->label !== null) {
            return $this->label;
        }

        return trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($this->model)));
    }

    public function query(): Builder
    {
        $builder = (new $this->model)->newQuery();
        if ($this->modifyQueryUsing !== null) {
            $modified = ($this->modifyQueryUsing)($builder, $this);
            if ($modified !== null && ! $modified instanceof Builder) {
                throw new \UnexpectedValueException('A MorphTo options query callback must return an Eloquent Builder or null.');
            }
            $builder = $modified ?? $builder;
        }

        return $builder;
    }

    public function resolve(string|int $key): ?Model
    {
        if ($this->staticOptions !== null && ! array_key_exists($key, $this->staticOptions) && ! array_key_exists((string) $key, $this->staticOptions)) {
            return null;
        }

        return $this->query()->whereKey($key)->first();
    }

    /** @return list<array{value: string|int, label: string}> */
    public function options(): array
    {
        if ($this->staticOptions !== null) {
            return array_map(static fn (string|int $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($this->staticOptions), array_values($this->staticOptions));
        }
        $model = new $this->model;

        return $this->query()->orderBy($this->titleAttribute)->limit($this->optionsLimit)
            ->get([$model->getKeyName(), $this->titleAttribute])
            ->map(fn (Model $record): array => ['value' => $record->getKey(), 'label' => (string) $record->getAttribute($this->titleAttribute)])
            ->values()->all();
    }

    /** @return list<array{value: string|int, label: string}> */
    public function searchOptions(string $search): array
    {
        if (mb_strlen($search) > 200) {
            throw new \InvalidArgumentException('MorphTo search text cannot exceed 200 characters.');
        }
        if ($this->staticOptions !== null) {
            return array_values(array_filter($this->options(), static fn (array $option): bool => $search === '' || str_contains(mb_strtolower($option['label']), mb_strtolower($search))));
        }
        $model = new $this->model;

        return $this->query()->when($search !== '', fn (Builder $query): Builder => $query->where($this->titleAttribute, 'like', '%'.$search.'%'))
            ->orderBy($this->titleAttribute)->limit($this->optionsLimit)
            ->get([$model->getKeyName(), $this->titleAttribute])
            ->map(fn (Model $record): array => ['value' => $record->getKey(), 'label' => (string) $record->getAttribute($this->titleAttribute)])
            ->values()->all();
    }

    /** @return array{value: string|int, label: string}|null */
    public function selectedOption(string|int $key): ?array
    {
        $record = $this->resolve($key);

        return $record === null ? null : ['value' => $record->getKey(), 'label' => (string) $record->getAttribute($this->titleAttribute)];
    }

    /** @param list<array{value: string|int, label: string}> $options */
    public function serializeWithOptions(array $options): array
    {
        return ['alias' => $this->wireAlias(), 'label' => $this->displayLabel(), 'options' => $options];
    }

    public function jsonSerialize(): array
    {
        return ['alias' => $this->wireAlias(), 'label' => $this->displayLabel(), 'options' => $this->options()];
    }
}
