<?php

declare(strict_types=1);

namespace Inlay\Core;

use InvalidArgumentException;
use Stringable;

final readonly class ContractVersion implements Stringable
{
    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
    ) {
    }

    public static function from(string $version): self
    {
        if (preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', trim($version), $matches) !== 1) {
            throw new InvalidArgumentException("Invalid contract version [{$version}]. Expected major.minor.patch.");
        }

        return new self((int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    public function satisfies(string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (preg_split('/\s*,\s*|\s+/', $constraint) ?: [] as $part) {
            if ($part !== '' && ! $this->satisfiesPart($part)) {
                return false;
            }
        }

        return true;
    }

    private function satisfiesPart(string $constraint): bool
    {
        if (preg_match('/^(\^|~|>=|<=|>|<|=)?v?(\d+)(?:\.(\d+|\*))?(?:\.(\d+|\*))?$/', $constraint, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid contract constraint [{$constraint}].");
        }

        $operator = $matches[1] ?? '';
        $minorToken = $matches[3] ?? null;
        $patchToken = $matches[4] ?? null;
        $major = (int) $matches[2];
        $minor = $minorToken === null || $minorToken === '*' ? 0 : (int) $minorToken;
        $patch = $patchToken === null || $patchToken === '*' ? 0 : (int) $patchToken;
        $lower = new self($major, $minor, $patch);

        if ($operator === '^') {
            $upper = $major > 0
                ? new self($major + 1, 0, 0)
                : ($minor > 0 ? new self(0, $minor + 1, 0) : new self(0, 0, $patch + 1));

            return $this->compare($lower) >= 0 && $this->compare($upper) < 0;
        }

        if ($operator === '~') {
            $upper = $minorToken === null
                ? new self($major + 1, 0, 0)
                : new self($major, $minor + 1, 0);

            return $this->compare($lower) >= 0 && $this->compare($upper) < 0;
        }

        if (in_array($operator, ['', '='], true) && ($minorToken === null || $minorToken === '*' || $patchToken === '*')) {
            return $this->major === $major
                && ($minorToken === null || $minorToken === '*' || $this->minor === $minor);
        }

        $comparison = $this->compare($lower);

        return match ($operator) {
            '>' => $comparison > 0,
            '>=' => $comparison >= 0,
            '<' => $comparison < 0,
            '<=' => $comparison <= 0,
            '', '=' => $comparison === 0,
            default => false,
        };
    }

    public function __toString(): string
    {
        return "{$this->major}.{$this->minor}.{$this->patch}";
    }
}
