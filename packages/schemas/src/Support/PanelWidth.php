<?php

declare(strict_types=1);

namespace Inlay\Schemas\Support;

/**
 * The one list of widths Inlay's floating surfaces accept.
 *
 * An action modal and a table's filter panel are the same kind of thing — a
 * surface that opens over the page — so they offer the same sizes. Keeping the
 * list here is what stops one of them accepting a width the other refuses.
 */
final class PanelWidth
{
    /** @var list<string> */
    public const NAMES = ['xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl', '7xl', 'screen'];

    private function __construct() {}

    /**
     * @param  class-string<\Throwable>  $exception
     *
     * @throws \Throwable when the width is not one Inlay offers.
     */
    public static function assert(string $width, string $subject, string $exception = \InvalidArgumentException::class): void
    {
        if (! in_array($width, self::NAMES, true)) {
            throw new $exception("Unsupported {$subject} [{$width}].");
        }
    }
}
