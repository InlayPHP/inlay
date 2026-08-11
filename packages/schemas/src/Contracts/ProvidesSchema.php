<?php

declare(strict_types=1);

namespace Inlay\Schemas\Contracts;

use Inlay\Schemas\Component;

/**
 * A reusable, embeddable group of schema components.
 *
 * Applications and community packages implement this to publish a schema
 * fragment that Forms, Infolists, and layout containers can embed directly,
 * without exporting an array-returning helper of their own.
 */
interface ProvidesSchema
{
    /** @return list<Component> */
    public function schemaComponents(): array;
}
