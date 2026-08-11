<?php

declare(strict_types=1);

namespace Inlay\Schemas\Support;

/**
 * The one list of action-row alignments Inlay accepts.
 *
 * This aligns a group of buttons within its container, which is why it carries
 * `between` where {@see ContentAlignment} does not: `between` has no meaning for
 * text inside its own box, and keeping the two lists apart stops it leaking there.
 *
 * The list was inlined in `Callout` while every other component that renders an
 * action row hardcoded its alignment in each renderer instead. That is how a
 * section's footer actions came to sit at the trailing edge in React and the
 * leading edge in Vue: nothing was serialized, so each renderer chose.
 */
final class ActionAlignment
{
    /** @var list<string> */
    public const NAMES = ['start', 'center', 'end', 'between'];

    /**
     * Header actions sit beside a heading, so they align to the trailing edge;
     * footer actions read as a continuation of the content above them, so they
     * align to the leading edge. Both match the documented defaults.
     */
    public const HEADER_DEFAULT = 'end';

    public const FOOTER_DEFAULT = 'start';

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
