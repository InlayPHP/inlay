<?php

declare(strict_types=1);

namespace Inlay\Concerns;

use InvalidArgumentException;

trait HasSafeAttributes
{
    /** @var array<string, scalar|null> */
    private array $extraAttributes = [];

    /** @param array<string, scalar|null> $attributes */
    public function extraAttributes(array $attributes): static
    {
        foreach ($attributes as $name => $value) {
            if (! is_string($name) || ! self::isSafeAttributeName($name)) {
                throw new InvalidArgumentException("Unsafe navigation attribute [{$name}].");
            }

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Navigation attribute [{$name}] must be scalar or null.");
            }
        }

        $this->extraAttributes = [...$this->extraAttributes, ...$attributes];

        return $this;
    }

    /** @return array<string, scalar|null> */
    protected function serializedExtraAttributes(): array
    {
        return $this->extraAttributes;
    }

    private static function isSafeAttributeName(string $name): bool
    {
        return in_array($name, ['class', 'id', 'title', 'rel', 'target'], true)
            || str_starts_with($name, 'aria-')
            || str_starts_with($name, 'data-');
    }
}
