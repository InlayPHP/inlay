<?php

declare(strict_types=1);

namespace Inlay\Infolists;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Model;
use Inlay\Infolists\Entries\ImageEntry;
use Inlay\Infolists\Entries\TextEntry;
use Inlay\Infolists\Support\ImageUrlResolver;
use Inlay\Schemas\Component;
use Inlay\Schemas\Schema;
use Inlay\Schemas\SchemaContext;
use InvalidArgumentException;
use JsonSerializable;

final class Infolist implements JsonSerializable
{
    private Schema $schemaKernel;

    /** @var array<string, mixed> */
    private array $data = [];

    private mixed $record = null;

    private string $operation = 'view';

    private ?FilesystemFactory $filesystems = null;

    private function __construct(private readonly string $name)
    {
        $this->schemaKernel = Schema::make($name)->operation($this->operation);
    }

    public static function make(string $name = 'infolist'): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('An infolist name cannot be empty.');
        }

        return new self($name);
    }

    /** @param list<Component>|Closure $components */
    public function schema(array|Closure $components): self
    {
        $this->schemaKernel->components($components);

        return $this;
    }

    public function schemaKernel(): Schema
    {
        return $this->schemaKernel;
    }

    /** @param array<string, mixed> $data */
    public function data(array $data): self
    {
        $this->data = $data;
        $this->schemaKernel->state($data);

        return $this;
    }

    public function columns(int $columns): self
    {
        $this->schemaKernel->columns($columns);

        return $this;
    }

    public function operation(string $operation): self
    {
        $operation = trim($operation);
        $this->schemaKernel->operation($operation);
        $this->operation = $operation;

        return $this;
    }

    public function record(mixed $record): self
    {
        $this->record = $record;
        $this->schemaKernel->record($record);

        return $this;
    }

    /** Override Laravel's filesystem binding, primarily for isolated hosts and tests. */
    public function filesystem(FilesystemFactory $filesystems): self
    {
        $this->filesystems = $filesystems;

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $this->schemaKernel->context(SchemaContext::make($this->data, $this->operation, $this->record));
        $data = $this->resolvedAggregateData($this->data);
        // Aggregate values are authoritative record state and are available to
        // presentation/visibility callbacks. Display-only formatters and image
        // URLs below must not replace that raw schema context.
        $this->schemaKernel->state($data);
        $this->schemaKernel->context(SchemaContext::make($data, $this->operation, $this->record));
        $data = $this->resolvedFormattedTextData($data);
        $data = $this->resolvedImageData($data);

        return [
            'contract' => 'inlay.infolists.v1',
            'type' => 'infolist',
            'name' => $this->name,
            'columns' => $this->schemaKernel->getColumns(),
            'data' => (object) $data,
            'schema' => $this->schemaKernel->getComponents(),
        ];
    }

    /** @return array<string, mixed> */
    private function resolvedImageData(array $data): array
    {
        $resolver = new ImageUrlResolver($this->resolvedFilesystemFactory());

        foreach ($this->schemaKernel->getFlatComponents() as $component) {
            if (! $component instanceof ImageEntry) {
                continue;
            }

            $path = $component->getStatePath();
            if ($path === '') {
                continue;
            }

            $this->transformAtPath(
                $data,
                explode('.', $path),
                static fn (mixed $state): mixed => $resolver->resolve($component, $state),
            );
        }

        return $data;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function resolvedFormattedTextData(array $data): array
    {
        foreach ($this->schemaKernel->getFlatComponents() as $component) {
            if (! $component instanceof TextEntry || ! $component->shouldTransformStateForDisplay()) {
                continue;
            }

            $path = $component->getStatePath();
            if ($path === '') {
                continue;
            }

            $this->transformAtPath(
                $data,
                explode('.', $path),
                static fn (mixed $state): mixed => $component->formatState($state),
            );
        }

        return $data;
    }

    /**
     * Resolve every relationship aggregate in grouped SQL queries while keeping
     * relationship scopes and aggregate metadata entirely on the server.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolvedAggregateData(array $data): array
    {
        $entries = [];
        $countEntries = [];
        foreach ($this->schemaKernel->getFlatComponents() as $component) {
            if (! $component instanceof TextEntry) {
                continue;
            }

            $aggregate = $component->relationshipAggregateDefinition();
            if ($aggregate !== null) {
                $entries[] = [$component, $aggregate];
            }

            $counts = $component->relationshipCountDefinition();
            if ($counts !== null) {
                $countEntries[] = [$component, $counts];
            }
        }

        if ($entries === [] && $countEntries === []) {
            return $data;
        }
        if (! $this->record instanceof Model || ! $this->record->exists) {
            throw new \LogicException('Text entry relationship aggregates and counts require a persisted Eloquent record supplied through Infolist::record().');
        }

        $record = clone $this->record;
        $groups = [];
        $directEntries = [];
        foreach ($entries as $index => [$entry, $aggregate]) {
            $relationship = $aggregate['relationship'];
            $column = $aggregate['column'];

            // A single relationship and a plain column can share one SQL
            // aggregate query. Lists, expressions, and null values must use
            // Laravel's native loadAggregate contract and read the entry's
            // generated attribute afterwards.
            if (! is_string($column) || $relationship === null || (! is_string($relationship) && (array_is_list($relationship) || count($relationship) !== 1))) {
                $directEntries[] = [$entry, $aggregate];
                continue;
            }

            $alias = '_inlay_infolist_aggregate_'.$index;
            if (is_string($relationship)) {
                $relationValue = $relationship.' as '.$alias;
            } else {
                $relationName = array_key_first($relationship);
                $relationValue = [$relationName.' as '.$alias => $relationship[$relationName]];
            }

            $group = $aggregate['function'].'\0'.$aggregate['column'];
            $groups[$group]['function'] = $aggregate['function'];
            $groups[$group]['column'] = $aggregate['column'];
            $groups[$group]['relationships'][] = $relationValue;
            $groups[$group]['entries'][] = [$entry, $alias];
        }

        foreach ($groups as $group) {
            $relationships = [];
            foreach ($group['relationships'] as $relationship) {
                if (is_string($relationship)) {
                    $relationships[] = $relationship;
                } else {
                    $relationships += $relationship;
                }
            }

            $record->loadAggregate($relationships, $group['column'], $group['function']);
            foreach ($group['entries'] as [$entry, $alias]) {
                $this->setDataAtPath($data, explode('.', $entry->getStatePath()), $record->getAttribute($alias));
            }
        }

        foreach ($directEntries as [$entry, $aggregate]) {
            if ($aggregate['relationship'] !== null) {
                $record->loadAggregate(
                    $aggregate['relationship'],
                    $aggregate['column'],
                    $aggregate['function'],
                );
            }

            $this->setDataAtPath(
                $data,
                explode('.', $entry->getStatePath()),
                $aggregate['relationship'] === null
                    ? null
                    : $record->getAttributeValue($entry->name()),
            );
        }

        foreach ($countEntries as [$entry, $relationships]) {
            // Keep Laravel's native count attribute names so scoped and
            // community-defined relationship callbacks behave exactly like
            // loadCount() outside an infolist.
            $record->loadCount($relationships);
            $this->setDataAtPath(
                $data,
                explode('.', $entry->getStatePath()),
                $record->getAttributeValue($entry->name()),
            );
        }

        return $data;
    }

    /** @param list<string> $segments */
    private function setDataAtPath(array &$data, array $segments, mixed $value): void
    {
        $cursor = &$data;
        foreach ($segments as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor = $value;
    }

    /** @param list<string> $segments */
    private function transformAtPath(mixed &$value, array $segments, Closure $transform): void
    {
        if ($segments === []) {
            $value = $transform($value);

            return;
        }
        if (! is_array($value)) {
            return;
        }

        $segment = $segments[0];
        if (array_key_exists($segment, $value)) {
            array_shift($segments);
            $this->transformAtPath($value[$segment], $segments, $transform);

            return;
        }

        // Repeatable entries contribute a named state segment, while each item
        // contributes an implicit numeric segment that is not in the PHP schema.
        if (array_is_list($value)) {
            foreach ($value as &$item) {
                $this->transformAtPath($item, $segments, $transform);
            }
            unset($item);
        }
    }

    private function resolvedFilesystemFactory(): ?FilesystemFactory
    {
        if ($this->filesystems !== null) {
            return $this->filesystems;
        }

        $container = Container::getInstance();
        foreach ([FilesystemFactory::class, 'filesystem'] as $binding) {
            if (! $container->bound($binding)) {
                continue;
            }

            try {
                $filesystems = $container->make($binding);
            } catch (\Throwable) {
                // A lightweight test or package consumer may leave an alias in
                // the container without registering Laravel's filesystem
                // manager. Treat that the same as an absent optional binding.
                continue;
            }

            if ($filesystems instanceof FilesystemFactory) {
                return $filesystems;
            }
        }

        return null;
    }
}
