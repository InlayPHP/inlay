<?php

declare(strict_types=1);

namespace Inlay\Support;

use InvalidArgumentException;
use JsonSerializable;

final class Condition implements JsonSerializable
{
    /** @var list<string> */
    private const OPERATORS = [
        'equals',
        'not-equals',
        'in',
        'not-in',
        'truthy',
        'falsy',
        'filled',
        'blank',
    ];

    /** @param list<Condition> $conditions */
    private function __construct(
        private readonly ?string $path = null,
        private readonly ?string $operator = null,
        private readonly mixed $value = null,
        private readonly ?string $logic = null,
        private readonly array $conditions = [],
    ) {
        if ($logic !== null) {
            if (! in_array($logic, ['all', 'any', 'not'], true)) {
                throw new InvalidArgumentException("Unsupported condition logic [{$logic}].");
            }

            if ($conditions === []) {
                throw new InvalidArgumentException('A condition group cannot be empty.');
            }

            if ($logic === 'not' && count($conditions) !== 1) {
                throw new InvalidArgumentException('A not condition requires exactly one child condition.');
            }

            return;
        }

        if ($path === null || trim($path) === '') {
            throw new InvalidArgumentException('A condition path cannot be empty.');
        }

        if ($operator === null || ! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported condition operator [{$operator}].");
        }

        if (in_array($operator, ['in', 'not-in'], true) && ! is_array($value)) {
            throw new InvalidArgumentException("Condition operator [{$operator}] requires an array value.");
        }
    }

    public static function make(string $path, mixed $value = true, string $operator = 'equals'): self
    {
        return new self($path, $operator, $value);
    }

    public static function all(self ...$conditions): self
    {
        return new self(logic: 'all', conditions: $conditions);
    }

    public static function any(self ...$conditions): self
    {
        return new self(logic: 'any', conditions: $conditions);
    }

    public static function not(self $condition): self
    {
        return new self(logic: 'not', conditions: [$condition]);
    }

    public static function truthy(string $path): self
    {
        return new self($path, 'truthy', null);
    }

    public static function falsy(string $path): self
    {
        return new self($path, 'falsy', null);
    }

    public static function filled(string $path): self
    {
        return new self($path, 'filled', null);
    }

    public static function blank(string $path): self
    {
        return new self($path, 'blank', null);
    }

    public function path(): string
    {
        return $this->path ?? throw new \LogicException('Condition groups do not have a path.');
    }

    public function operator(): string
    {
        return $this->operator ?? throw new \LogicException('Condition groups do not have an operator.');
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function isLeaf(): bool
    {
        return $this->logic === null;
    }

    public function logic(): ?string
    {
        return $this->logic;
    }

    /** @return list<Condition> */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /** @param array<string, mixed> $state */
    public function matches(array $state): bool
    {
        if ($this->logic !== null) {
            return match ($this->logic) {
                'all' => $this->allMatch($state),
                'any' => $this->anyMatches($state),
                'not' => ! $this->conditions[0]->matches($state),
            };
        }

        $current = self::get($state, $this->path());

        return match ($this->operator()) {
            'equals' => self::equal($current, $this->value),
            'not-equals' => ! self::equal($current, $this->value),
            'in' => self::includes($this->value, $current),
            'not-in' => ! self::includes($this->value, $current),
            'truthy' => self::truthyValue($current),
            'falsy' => ! self::truthyValue($current),
            'filled' => ! self::blankValue($current),
            'blank' => self::blankValue($current),
        };
    }

    /** @return array{path: string, operator: string, value: mixed}|array{logic: string, conditions: list<Condition>} */
    public function jsonSerialize(): array
    {
        if ($this->logic !== null) {
            return [
                'logic' => $this->logic,
                'conditions' => $this->conditions,
            ];
        }

        return [
            'path' => $this->path(),
            'operator' => $this->operator(),
            'value' => $this->value,
        ];
    }

    /** @param array<string, mixed> $state */
    private static function get(array $state, string $path): mixed
    {
        $value = $state;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private static function equal(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return (float) $left === (float) $right;
        }
        if (! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }
        if (count($left) !== count($right) || array_is_list($left) !== array_is_list($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right) || ! self::equal($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $state */
    private function allMatch(array $state): bool
    {
        foreach ($this->conditions as $condition) {
            if (! $condition->matches($state)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $state */
    private function anyMatches(array $state): bool
    {
        foreach ($this->conditions as $condition) {
            if ($condition->matches($state)) {
                return true;
            }
        }

        return false;
    }

    private static function includes(mixed $haystack, mixed $needle): bool
    {
        if (! is_array($haystack)) {
            return false;
        }
        foreach ($haystack as $value) {
            if (self::equal($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function blankValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }
        if (is_object($value)) {
            return get_object_vars($value) === [];
        }

        return false;
    }

    private static function truthyValue(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return false;
        }
        if ((is_int($value) || is_float($value)) && ((float) $value === 0.0 || is_nan((float) $value))) {
            return false;
        }

        return true;
    }
}
