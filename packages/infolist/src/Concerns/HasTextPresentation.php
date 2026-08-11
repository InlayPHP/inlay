<?php

declare(strict_types=1);

namespace Inlay\Infolists\Concerns;

use Closure;
use Inlay\Schemas\Support\TextPresentation;

/**
 * Text sizing for the entries that actually render words.
 *
 * `color()` and `tooltip()` live on every entry because an image and an icon
 * have both; a font weight only means something where there is text, and
 * `ImageEntry::size()` already means pixels, so this stays opt-in rather than
 * being pushed onto the base class.
 */
trait HasTextPresentation
{
    protected string|Closure $size = 'medium';

    protected string|Closure $weight = 'normal';

    protected string|Closure $fontFamily = 'sans';

    public function size(string|Closure $size): static
    {
        TextPresentation::assertSize($size, 'entry');

        $this->size = $size;

        return $this;
    }

    public function weight(string|Closure $weight): static
    {
        TextPresentation::assertWeight($weight, 'entry');

        $this->weight = $weight;

        return $this;
    }

    public function fontFamily(string|Closure $family): static
    {
        TextPresentation::assertFontFamily($family, 'entry');

        $this->fontFamily = $family;

        return $this;
    }

    /**
     * @internal
     *
     * @return array{size: string, weight: string, fontFamily: string}
     */
    protected function textPresentation(): array
    {
        $size = $this->resolvePresentationString($this->size, 'entry size', nullable: false);
        $weight = $this->resolvePresentationString($this->weight, 'entry weight', nullable: false);
        $fontFamily = $this->resolvePresentationString($this->fontFamily, 'entry font family', nullable: false);

        // A closure is only checked once it has produced something, so the same
        // vocabulary is enforced whether the value was declared or computed.
        TextPresentation::assertResolved($size, TextPresentation::SIZES, 'entry size');
        TextPresentation::assertResolved($weight, TextPresentation::WEIGHTS, 'entry weight');
        TextPresentation::assertResolved($fontFamily, TextPresentation::FONT_FAMILIES, 'entry font family');

        return ['size' => $size, 'weight' => $weight, 'fontFamily' => $fontFamily];
    }
}
