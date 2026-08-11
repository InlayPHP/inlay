<?php

declare(strict_types=1);

namespace Inlay\Schemas;

final readonly class SchemaContext
{
    /** @param array<string, mixed> $state */
    public function __construct(
        public array $state = [],
        public string $operation = 'default',
        public mixed $record = null,
    ) {
        if (trim($this->operation) === '') {
            throw new \InvalidArgumentException('A schema operation cannot be empty.');
        }
    }

    /** @param array<string, mixed> $state */
    public static function make(array $state = [], string $operation = 'default', mixed $record = null): self
    {
        return new self($state, trim($operation), $record);
    }

    public function get(string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $this->state;
        }

        $value = $this->state;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
