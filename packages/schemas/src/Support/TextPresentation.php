<?php

declare(strict_types=1);

namespace Inlay\Schemas\Support;

/**
 * The one list of text sizes, weights, and font families Inlay accepts.
 *
 * Text and infolist entries both offer the same presentation vocabulary, and
 * each checks it twice — once when it is declared and again once a closure has
 * resolved. Keeping the lists here is what stops those four checks from
 * drifting apart and letting a value through in one place that another refuses.
 */
final class TextPresentation
{
    /** @var list<string> */
    public const SIZES = ['extra-small', 'small', 'medium', 'large'];

    /** @var list<string> */
    public const WEIGHTS = ['thin', 'extra-light', 'light', 'normal', 'medium', 'semibold', 'bold', 'extra-bold', 'black'];

    /** @var list<string> */
    public const FONT_FAMILIES = ['sans', 'serif', 'mono'];

    private function __construct() {}

    /** @throws \InvalidArgumentException when a declared value is not offered. */
    public static function assertSize(mixed $size, string $subject = 'text'): void
    {
        self::assert($size, self::SIZES, $subject.' size');
    }

    public static function assertWeight(mixed $weight, string $subject = 'text'): void
    {
        self::assert($weight, self::WEIGHTS, $subject.' weight');
    }

    public static function assertFontFamily(mixed $family, string $subject = 'text'): void
    {
        self::assert($family, self::FONT_FAMILIES, $subject.' font family');
    }

    /**
     * The same check for a value a closure produced, which fails later and so
     * reports differently: an author cannot fix a resolved value at the call site.
     *
     * @param  list<string>  $allowed
     *
     * @throws \UnexpectedValueException
     */
    public static function assertResolved(mixed $value, array $allowed, string $subject): void
    {
        if (! in_array($value, $allowed, true)) {
            $shown = is_scalar($value) ? (string) $value : get_debug_type($value);

            throw new \UnexpectedValueException("Unsupported resolved {$subject} [{$shown}].");
        }
    }

    /** @param list<string> $allowed */
    private static function assert(mixed $value, array $allowed, string $subject): void
    {
        if (is_string($value) && ! in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported '.$subject.'.');
        }
    }
}
