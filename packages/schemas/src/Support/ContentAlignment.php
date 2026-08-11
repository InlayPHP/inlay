<?php

declare(strict_types=1);

namespace Inlay\Schemas\Support;

/**
 * The one list of content alignments Inlay accepts.
 *
 * This is where text sits inside its own box — a table cell, an infolist entry.
 * Action rows use a different vocabulary (`start`/`center`/`end`/`between`),
 * because those align a group within a container rather than text within a box,
 * and conflating the two would let `between` through where it means nothing.
 */
final class ContentAlignment
{
    /** @var list<string> */
    public const NAMES = ['left', 'center', 'right'];

    private function __construct() {}

    /**
     * @param  class-string<\Throwable>  $exception
     *
     * @throws \Throwable when the alignment is not one Inlay offers.
     */
    public static function assert(string $alignment, string $subject, string $exception = \InvalidArgumentException::class): void
    {
        if (! in_array($alignment, self::NAMES, true)) {
            throw new $exception("Unsupported {$subject} [{$alignment}].");
        }
    }
}
