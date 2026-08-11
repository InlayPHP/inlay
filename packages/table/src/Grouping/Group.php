<?php

declare(strict_types=1);

namespace Inlay\Tables\Grouping;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Inlay\Support\ClosureEvaluator;
use Inlay\Support\Concerns\Configurable;
use JsonSerializable;

final class Group implements JsonSerializable
{
    use Configurable;

    private ?string $label = null;

    private bool $collapsible = false;

    private bool $date = false;

    private bool $titlePrefixedWithLabel = true;

    private ?string $relationship = null;

    private ?string $relationshipAttribute = null;

    private ?Closure $titleResolver = null;

    private ?Closure $descriptionResolver = null;

    private ?Closure $keyResolver = null;

    private ?Closure $orderQuery = null;

    private ?Closure $scopeQueryByKey = null;

    private ?Closure $groupQuery = null;

    private function __construct(private readonly string $name)
    {
        $this->applyGlobalConfiguration();
    }

    public static function make(string $name): self
    {
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

    public function collapsible(bool $enabled = true): self
    {
        $this->collapsible = $enabled;

        return $this;
    }

    public function date(bool $enabled = true): self
    {
        $this->date = $enabled;

        return $this;
    }

    public function titlePrefixedWithLabel(bool $enabled = true): self
    {
        $this->titlePrefixedWithLabel = $enabled;

        return $this;
    }

    public function relationship(string $name, string $titleAttribute = 'name'): self
    {
        $this->validateIdentifier($this->name, 'group alias');
        $this->relationship = $this->validateRelationshipPath($name);
        $this->relationshipAttribute = $this->validateIdentifier($titleAttribute, 'relationship title attribute');

        return $this;
    }

    public function prepareQuery(Builder $query): void
    {
        $relationship = $this->relationshipDefinition();
        if ($relationship === null) {
            return;
        }

        if ($relationship['explicit']) {
            $query->withAggregate($relationship['name'].' as '.$this->name, $relationship['attribute']);

            return;
        }

        $query->with($relationship['name']);
    }

    public function getTitleFromRecordUsing(Closure $resolver): self
    {
        $this->titleResolver = $resolver;

        return $this;
    }

    public function getDescriptionFromRecordUsing(Closure $resolver): self
    {
        $this->descriptionResolver = $resolver;

        return $this;
    }

    public function getKeyFromRecordUsing(Closure $resolver): self
    {
        $this->keyResolver = $resolver;

        return $this;
    }

    public function orderQueryUsing(Closure $callback): self
    {
        $this->orderQuery = $callback;

        return $this;
    }

    public function scopeQueryByKeyUsing(Closure $callback): self
    {
        $this->scopeQueryByKey = $callback;

        return $this;
    }

    public function groupQueryUsing(Closure $callback): self
    {
        $this->groupQuery = $callback;

        return $this;
    }

    public function orderQuery(Builder $query, string $direction): void
    {
        if ($this->orderQuery !== null) {
            ClosureEvaluator::evaluate($this->orderQuery, [
                'direction' => $direction,
                'group' => $this,
                'query' => $query,
            ], [Builder::class => $query, self::class => $this], [$query, $direction]);

            return;
        }
        $relationship = $this->relationshipDefinition();
        if ($relationship === null) {
            $query->orderBy($this->name, $direction);

            return;
        }
        if ($relationship['explicit']) {
            $query->orderBy($this->name, $direction);

            return;
        }
        $alias = $this->relationshipAlias();
        $query->withAggregate($relationship['name'].' as '.$alias, $relationship['attribute'])->orderBy($alias, $direction);
    }

    public function scopeQueryByKey(Builder $query, mixed $key): bool
    {
        if ($this->scopeQueryByKey !== null) {
            ClosureEvaluator::evaluate($this->scopeQueryByKey, [
                'group' => $this,
                'key' => $key,
                'query' => $query,
            ], [Builder::class => $query, self::class => $this], [$query, $key]);

            return true;
        }
        $relationship = $this->relationshipDefinition();
        if ($relationship !== null) {
            $query->whereHas(
                $relationship['name'],
                fn (Builder $related): Builder => $related->where($relationship['attribute'], $key),
            );

            return true;
        }
        $this->date ? $query->whereDate($this->name, (string) $key) : $query->where($this->name, $key);

        return true;
    }

    public function groupQuery(Builder $query): void
    {
        if ($this->groupQuery !== null) {
            ClosureEvaluator::evaluate($this->groupQuery, [
                'group' => $this,
                'query' => $query,
            ], [Builder::class => $query, self::class => $this], [$query]);

            return;
        }
        if (! str_contains($this->name, '.')) {
            $query->groupBy($this->name);
        }
    }

    /** @param array<string, mixed> $row
     * @return array{key: string, title: string, description: string|null}
     */
    public function resolve(array $row): array
    {
        $value = $this->valueAtPath($row, $this->name);
        if ($this->date && is_string($value)) {
            $value = substr($value, 0, 10);
        }
        $utilities = ['group' => $this, 'record' => $row, 'row' => $row, 'value' => $value];
        $types = [self::class => $this];
        $key = $this->keyResolver === null ? $value : ClosureEvaluator::evaluate($this->keyResolver, $utilities, $types, [$row]);
        $title = $this->titleResolver === null ? $value : ClosureEvaluator::evaluate($this->titleResolver, $utilities, $types, [$row]);
        $description = $this->descriptionResolver === null ? null : ClosureEvaluator::evaluate($this->descriptionResolver, $utilities, $types, [$row]);
        $keyString = is_scalar($key) || $key === null ? (string) ($key ?? '') : json_encode($key, JSON_THROW_ON_ERROR);
        $titleString = is_scalar($title) || $title === null ? (string) ($title ?? 'Blank') : $keyString;

        return [
            'key' => $keyString,
            'title' => $this->titlePrefixedWithLabel ? $this->getLabel().': '.$titleString : $titleString,
            'description' => is_scalar($description) ? (string) $description : null,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->getLabel(),
            'collapsible' => $this->collapsible,
            'date' => $this->date,
            'titlePrefixedWithLabel' => $this->titlePrefixedWithLabel,
        ];
    }

    private function getLabel(): string
    {
        return $this->label ?? ucwords(str_replace(['_', '-', '.'], ' ', $this->name));
    }

    /** @return array{name: string, attribute: string, explicit: bool}|null */
    private function relationshipDefinition(): ?array
    {
        if ($this->relationship !== null && $this->relationshipAttribute !== null) {
            return ['name' => $this->relationship, 'attribute' => $this->relationshipAttribute, 'explicit' => true];
        }

        $position = strrpos($this->name, '.');
        if ($position === false) {
            return null;
        }

        return [
            'name' => substr($this->name, 0, $position),
            'attribute' => substr($this->name, $position + 1),
            'explicit' => false,
        ];
    }

    private function relationshipAlias(): string
    {
        return 'inlay_group_'.substr(hash('sha256', $this->name), 0, 16);
    }

    private function validateRelationshipPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $path) !== 1) {
            throw new \InvalidArgumentException("Invalid group relationship [{$path}].");
        }

        return $path;
    }

    private function validateIdentifier(string $value, string $description): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new \InvalidArgumentException("Invalid {$description} [{$value}].");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function valueAtPath(array $row, string $path): mixed
    {
        $value = $row;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
