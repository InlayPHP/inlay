<?php

declare(strict_types=1);

namespace Inlay\Schemas\Support;

use Inlay\Schemas\Component;
use Inlay\Schemas\Components\Group;
use Inlay\Schemas\Contracts\ProvidesSchema;
use Inlay\Schemas\Schema;

/**
 * Flatten embeddable schema entries into one list of components.
 *
 * A schema list may hold components, nested lists of components, whole Schema
 * objects, and reusable ProvidesSchema fragments. Everything downstream — keys,
 * state paths, traversal, serialization — then works with plain components.
 */
final class SchemaComposition
{
    /**
     * @param  list<mixed>  $entries
     * @param  class-string<\Throwable>  $exception
     * @return list<Component>
     */
    public static function flatten(array $entries, string $message, string $exception = \InvalidArgumentException::class): array
    {
        $components = [];

        foreach ($entries as $entry) {
            if ($entry instanceof Component) {
                $components[] = $entry;

                continue;
            }
            if ($entry instanceof Schema) {
                $components[] = self::embed($entry);

                continue;
            }
            if ($entry instanceof ProvidesSchema) {
                $components = [...$components, ...self::flatten($entry->schemaComponents(), $message, $exception)];

                continue;
            }
            if (is_array($entry) && ($entry === [] || array_is_list($entry))) {
                $components = [...$components, ...self::flatten($entry, $message, $exception)];

                continue;
            }

            throw new $exception($message);
        }

        return array_values($components);
    }

    /**
     * Preserve an embedded schema's own layout and state path.
     *
     * Columns resolve lazily so a closure-backed embedded schema still sees the
     * host schema's context rather than the moment it was composed.
     */
    private static function embed(Schema $schema): Group
    {
        $group = Group::make($schema->name())
            ->columns(static fn (): int|array => $schema->getColumns())
            ->gap($schema->hasGap())
            ->dense($schema->isDense())
            ->schema($schema->getComponents());

        return $schema->getStatePath() === '' ? $group : $group->statePath($schema->getStatePath());
    }
}
