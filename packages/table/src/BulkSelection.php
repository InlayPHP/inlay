<?php

declare(strict_types=1);

namespace Inlay\Tables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final readonly class BulkSelection
{
    /** @param list<string|int> $keys */
    private function __construct(public string $mode, public array $keys) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $mode = $payload['mode'] ?? null;
        if (! in_array($mode, ['page', 'query'], true)) {
            throw ValidationException::withMessages(['selection.mode' => 'Selection mode must be page or query.']);
        }

        $source = $mode === 'page' ? ($payload['records'] ?? null) : ($payload['excluded'] ?? []);
        if (! is_array($source)) {
            throw ValidationException::withMessages(['selection' => 'Selection keys must be an array.']);
        }

        $keys = [];
        foreach ($source as $key) {
            if ((! is_string($key) && ! is_int($key)) || (is_string($key) && trim($key) === '')) {
                throw ValidationException::withMessages(['selection' => 'Selection keys must be non-empty strings or integers.']);
            }
            $keys[] = $key;
        }

        if (($mode === 'page' && $keys === []) || count($keys) > 5000) {
            throw ValidationException::withMessages(['selection' => 'A selection must contain between 1 and 5000 explicit keys.']);
        }
        if (count(array_unique(array_map(static fn (string|int $key): string => (string) $key, $keys))) !== count($keys)) {
            throw ValidationException::withMessages(['selection' => 'Selection keys must be unique.']);
        }

        return new self($mode, $keys);
    }

    public function apply(Builder $query): Builder
    {
        if ($this->mode === 'page') {
            return $query->whereKey($this->keys);
        }

        if ($this->keys !== []) {
            $query->whereNotIn($query->getModel()->getQualifiedKeyName(), $this->keys);
        }

        return $query;
    }

    /** @return array{mode: 'page'|'query', records?: list<string|int>, excluded?: list<string|int>} */
    public function toArray(): array
    {
        return $this->mode === 'page'
            ? ['mode' => 'page', 'records' => $this->keys]
            : ['mode' => 'query', 'excluded' => $this->keys];
    }
}
