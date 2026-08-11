<?php

declare(strict_types=1);

namespace Inlay\Schemas\Support;

/**
 * The one list of semantic colour names Inlay layouts accept.
 *
 * Sections, Callouts, and Empty States all offer the same names, and each
 * checks them twice — once when declared and again once a closure has
 * resolved. Keeping the list here is what stops those checks from drifting
 * apart and letting a colour through in one layout that another refuses.
 */
final class SemanticColor
{
    /** @var list<string> */
    public const NAMES = ['neutral', 'primary', 'info', 'success', 'warning', 'danger'];

    private function __construct() {}

    /**
     * @param  class-string<\Throwable>  $exception
     *
     * @throws \Throwable when the name is not one Inlay offers.
     */
    public static function assert(string $color, string $property, string $exception = \InvalidArgumentException::class): void
    {
        if (! in_array($color, self::NAMES, true)) {
            throw new $exception("Unsupported {$property} [{$color}].");
        }
    }
}
