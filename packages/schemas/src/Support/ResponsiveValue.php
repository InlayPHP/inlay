<?php

declare(strict_types=1);

namespace Inlay\Schemas\Support;

final class ResponsiveValue
{
    /** @var list<string> */
    private const BREAKPOINTS = ['default', 'sm', 'md', 'lg', 'xl', '2xl', '@sm', '@md', '@lg', '@xl', '@2xl', '!@sm', '!@md', '!@lg', '!@xl', '!@2xl'];

    /**
     * @param int|array<string, int> $value
     * @return int|array<string, int>
     */
    public static function normalize(int|array $value, string $label, ?int $minimum = null, ?int $maximum = null): int|array
    {
        if (is_int($value)) {
            self::assertRange($value, $label, $minimum, $maximum);

            return $value;
        }

        if ($value === []) {
            throw new \InvalidArgumentException("{$label} responsive values cannot be empty.");
        }

        $normalized = [];

        foreach ($value as $breakpoint => $item) {
            if (! is_string($breakpoint) || ! in_array($breakpoint, self::BREAKPOINTS, true)) {
                throw new \InvalidArgumentException("{$label} contains an unsupported breakpoint.");
            }

            if (! is_int($item)) {
                throw new \InvalidArgumentException("{$label} responsive values must be integers.");
            }

            self::assertRange($item, $label, $minimum, $maximum);
            $normalized[$breakpoint] = $item;
        }

        return $normalized;
    }

    /**
     * @param int|'full'|array<string, int|'full'> $value
     * @return int|array<string, int|'full'>
     */
    public static function normalizeColumnSpan(int|string|array $value): int|array
    {
        if ($value === 'full') {
            return ['default' => 1, 'lg' => 'full'];
        }

        if (is_int($value)) {
            self::assertRange($value, 'Column span', 1, 12);

            return $value;
        }

        if (! is_array($value) || $value === []) {
            throw new \InvalidArgumentException('Column span must be an integer, full, or a non-empty responsive array.');
        }

        $normalized = [];

        foreach ($value as $breakpoint => $item) {
            if (! is_string($breakpoint) || ! in_array($breakpoint, self::BREAKPOINTS, true)) {
                throw new \InvalidArgumentException('Column span contains an unsupported breakpoint.');
            }

            if ($item !== 'full' && ! is_int($item)) {
                throw new \InvalidArgumentException('Column span responsive values must be integers or full.');
            }

            if (is_int($item)) {
                self::assertRange($item, 'Column span', 1, 12);
            }

            $normalized[$breakpoint] = $item;
        }

        return $normalized;
    }

    /**
     * @param string|array<string, string> $value
     * @param list<string> $allowed
     * @return string|array<string, string>
     */
    public static function normalizeOptions(string|array $value, string $label, array $allowed): string|array
    {
        if (is_string($value)) {
            if (! in_array($value, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported {$label}.");
            }

            return $value;
        }

        if ($value === []) {
            throw new \InvalidArgumentException("{$label} responsive values cannot be empty.");
        }

        foreach ($value as $breakpoint => $item) {
            if (! is_string($breakpoint) || ! in_array($breakpoint, self::BREAKPOINTS, true)) {
                throw new \InvalidArgumentException("{$label} contains an unsupported breakpoint.");
            }
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                throw new \InvalidArgumentException("Unsupported {$label}.");
            }
        }

        return $value;
    }

    private static function assertRange(int $value, string $label, ?int $minimum, ?int $maximum): void
    {
        if ($minimum !== null && $value < $minimum) {
            throw new \InvalidArgumentException("{$label} must be at least {$minimum}.");
        }

        if ($maximum !== null && $value > $maximum) {
            throw new \InvalidArgumentException("{$label} must be at most {$maximum}.");
        }
    }
}
