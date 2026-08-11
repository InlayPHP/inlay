<?php

declare(strict_types=1);

namespace Inlay\Schemas\Concerns;

/**
 * Bind a layout container to a single-record relationship on the form model.
 *
 * The Schemas kernel only owns the name and the state path it implies. Forms
 * resolves the Eloquent relationship, hydrates the container, and writes it
 * back, so schemas keep working without a database.
 */
trait BindsRelationship
{
    public function relationship(?string $name = null): static
    {
        $name ??= $this->name();
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('A schema relationship name must be a valid PHP method name.');
        }

        $this->relationshipName = $name;

        // A container reads and writes the related record, so it nests state
        // beneath the relationship unless an explicit path was configured.
        return $this->getStatePathSegment() === null ? $this->statePath($name) : $this;
    }
}
