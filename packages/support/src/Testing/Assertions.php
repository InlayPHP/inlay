<?php

declare(strict_types=1);

namespace Inlay\Support\Testing;

use PHPUnit\Framework\Assert as PHPUnitAssert;

final class Assertions
{
    public static function true(bool $condition, string $message): void
    {
        if (class_exists(PHPUnitAssert::class)) {
            PHPUnitAssert::assertTrue($condition, $message);

            return;
        }
        if (! $condition) {
            throw new AssertionFailed($message);
        }
    }

    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        if (class_exists(PHPUnitAssert::class)) {
            PHPUnitAssert::assertSame($expected, $actual, $message);

            return;
        }
        if ($expected !== $actual) {
            throw new AssertionFailed(
                $message."\nExpected: ".self::export($expected)."\nActual: ".self::export($actual),
            );
        }
    }

    public static function fail(string $message): never
    {
        if (class_exists(PHPUnitAssert::class)) {
            PHPUnitAssert::fail($message);
        }

        throw new AssertionFailed($message);
    }

    private static function export(mixed $value): string
    {
        return var_export($value, true);
    }
}
