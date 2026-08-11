<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns;

use Closure;
use Inlay\Support\SafeUrl;
use Inlay\Tables\Column;

final class ImageColumn extends Column
{
    private bool|Closure $circular = false;

    private int|Closure $size = 40;

    private int|Closure $width = 40;

    private int|Closure $height = 40;

    private bool|Closure $square = false;

    private bool|Closure $stacked = false;

    private int|Closure $ring = 3;

    private int|Closure $overlap = 4;

    private int|Closure|null $limit = null;

    private bool|Closure $limitedRemainingText = false;

    private bool|Closure $wrap = false;

    private string|Closure|null $fallbackUrl = null;

    private string|Closure|null $alt = null;

    protected function type(): string
    {
        return 'image-column';
    }

    public function circular(bool|Closure $enabled = true): self
    {
        $this->circular = $enabled;

        return $this;
    }

    public function size(int|Closure $pixels): self
    {
        return $this->imageSize($pixels);
    }

    public function imageSize(int|Closure $pixels): self
    {
        if (is_int($pixels)) {
            $this->assertDimension($pixels);
        }
        $this->size = $pixels;
        $this->width = $pixels;
        $this->height = $pixels;

        return $this;
    }

    public function imageWidth(int|Closure $pixels): self
    {
        if (is_int($pixels)) {
            $this->assertDimension($pixels);
        }
        $this->width = $pixels;

        return $this;
    }

    public function imageHeight(int|Closure $pixels): self
    {
        if (is_int($pixels)) {
            $this->assertDimension($pixels);
        }
        $this->height = $pixels;

        return $this;
    }

    public function square(bool|Closure $enabled = true): self
    {
        $this->square = $enabled;

        return $this;
    }

    public function stacked(bool|Closure $enabled = true): self
    {
        $this->stacked = $enabled;

        return $this;
    }

    public function ring(int|Closure $width): self
    {
        if (is_int($width) && ($width < 0 || $width > 8)) {
            throw new \InvalidArgumentException('Image stack ring widths must be between 0 and 8.');
        }
        $this->ring = $width;

        return $this;
    }

    public function overlap(int|Closure $overlap): self
    {
        if (is_int($overlap) && ($overlap < 0 || $overlap > 8)) {
            throw new \InvalidArgumentException('Image stack overlap must be between 0 and 8.');
        }
        $this->overlap = $overlap;

        return $this;
    }

    public function limit(int|Closure|null $images): self
    {
        if (is_int($images) && $images < 1) {
            throw new \InvalidArgumentException('Image limits must be at least 1.');
        }
        $this->limit = $images;

        return $this;
    }

    public function limitedRemainingText(bool|Closure $enabled = true): self
    {
        $this->limitedRemainingText = $enabled;

        return $this;
    }

    public function wrap(bool|Closure $enabled = true): self
    {
        $this->wrap = $enabled;

        return $this;
    }

    public function fallbackUrl(string|Closure|null $url): self
    {
        return $this->defaultImageUrl($url);
    }

    public function defaultImageUrl(string|Closure|null $url): self
    {
        $this->fallbackUrl = $url instanceof Closure || $url === null
            ? $url
            : SafeUrl::from($url)->value();

        return $this;
    }

    public function alt(string|Closure|null $alt): self
    {
        if (is_string($alt)) {
            $alt = $this->validateAlt($alt);
        }
        $this->alt = $alt;

        return $this;
    }

    public function hasRowPresentation(): bool
    {
        return parent::hasRowPresentation()
            || $this->circular instanceof Closure
            || $this->size instanceof Closure
            || $this->width instanceof Closure
            || $this->height instanceof Closure
            || $this->square instanceof Closure
            || $this->stacked instanceof Closure
            || $this->ring instanceof Closure
            || $this->overlap instanceof Closure
            || $this->limit instanceof Closure
            || $this->limitedRemainingText instanceof Closure
            || $this->wrap instanceof Closure
            || $this->alt instanceof Closure
            || $this->fallbackUrl instanceof Closure;
    }

    /** @param array<string, mixed> $row */
    public function resolveRowPresentation(array $row): array
    {
        $presentation = parent::resolveRowPresentation($row);
        $state = $presentation['state'];

        if ($this->circular instanceof Closure) {
            $presentation['circular'] = $this->resolveBoolean($this->circular, $row, $state, 'circular');
        }
        if ($this->size instanceof Closure) {
            $presentation['size'] = $this->resolveDimension($this->size, $row, $state, 'size');
        }
        if ($this->width instanceof Closure) {
            $presentation['width'] = $this->resolveDimension($this->width, $row, $state, 'width');
        }
        if ($this->height instanceof Closure) {
            $presentation['height'] = $this->resolveDimension($this->height, $row, $state, 'height');
        }
        if ($this->square instanceof Closure) {
            $presentation['square'] = $this->resolveBoolean($this->square, $row, $state, 'square');
        }
        if ($this->stacked instanceof Closure) {
            $presentation['stacked'] = $this->resolveBoolean($this->stacked, $row, $state, 'stacked');
        }
        if ($this->ring instanceof Closure) {
            $presentation['ring'] = $this->resolveRange($this->ring, $row, $state, 'ring', 0, 8);
        }
        if ($this->overlap instanceof Closure) {
            $presentation['overlap'] = $this->resolveRange($this->overlap, $row, $state, 'overlap', 0, 8);
        }
        if ($this->limit instanceof Closure) {
            $presentation['limit'] = $this->resolveLimit($this->limit, $row, $state);
        }
        if ($this->limitedRemainingText instanceof Closure) {
            $presentation['limitedRemainingText'] = $this->resolveBoolean($this->limitedRemainingText, $row, $state, 'limitedRemainingText');
        }
        if ($this->wrap instanceof Closure) {
            $presentation['wrap'] = $this->resolveBoolean($this->wrap, $row, $state, 'wrap');
        }

        if ($this->alt instanceof Closure) {
            $resolved = $this->evaluate($this->alt, $row, $state);
            if ($resolved !== null && ! is_scalar($resolved) && ! $resolved instanceof \Stringable) {
                throw new \UnexpectedValueException("Image column [{$this->name}] alt callbacks must return a scalar, stringable, or null.");
            }
            $presentation['alt'] = $resolved === null ? null : $this->validateAlt($resolved instanceof \Stringable ? (string) $resolved : (string) $resolved);
        }

        if ($this->fallbackUrl instanceof Closure) {
            $resolved = $this->evaluate($this->fallbackUrl, $row, $state);
            if ($resolved !== null && ! is_string($resolved)) {
                throw new \UnexpectedValueException("Image column [{$this->name}] fallback URL callbacks must return a string or null.");
            }
            $presentation['fallbackUrl'] = $resolved === null ? null : SafeUrl::from($resolved)->value();
        }

        return $presentation;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'circular' => is_bool($this->circular) ? $this->circular : false, 'size' => is_int($this->size) ? $this->size : 40, 'width' => is_int($this->width) ? $this->width : 40, 'height' => is_int($this->height) ? $this->height : 40, 'square' => is_bool($this->square) ? $this->square : false, 'stacked' => is_bool($this->stacked) ? $this->stacked : false, 'ring' => is_int($this->ring) ? $this->ring : 3, 'overlap' => is_int($this->overlap) ? $this->overlap : 4, 'limit' => is_int($this->limit) ? $this->limit : null, 'limitedRemainingText' => is_bool($this->limitedRemainingText) ? $this->limitedRemainingText : false, 'wrap' => is_bool($this->wrap) ? $this->wrap : false, 'fallbackUrl' => is_string($this->fallbackUrl) ? $this->fallbackUrl : null, 'alt' => is_string($this->alt) ? $this->alt : null];
    }

    private function resolveBoolean(Closure $value, array $row, mixed $state, string $kind): bool
    {
        $resolved = $this->evaluate($value, $row, $state);
        if (! is_bool($resolved)) {
            throw new \UnexpectedValueException("Image column [{$this->name}] {$kind} callbacks must return a boolean.");
        }

        return $resolved;
    }

    private function resolveDimension(Closure $value, array $row, mixed $state, string $kind): int
    {
        $resolved = $this->evaluate($value, $row, $state);
        if (! is_int($resolved)) {
            throw new \UnexpectedValueException("Image column [{$this->name}] {$kind} callbacks must return an integer.");
        }
        $this->assertDimension($resolved);

        return $resolved;
    }

    private function resolveRange(Closure $value, array $row, mixed $state, string $kind, int $minimum, int $maximum): int
    {
        $resolved = $this->evaluate($value, $row, $state);
        if (! is_int($resolved) || $resolved < $minimum || $resolved > $maximum) {
            throw new \UnexpectedValueException("Image column [{$this->name}] {$kind} callbacks must return an integer between {$minimum} and {$maximum}.");
        }

        return $resolved;
    }

    private function resolveLimit(Closure $value, array $row, mixed $state): ?int
    {
        $resolved = $this->evaluate($value, $row, $state);
        if ($resolved !== null && (! is_int($resolved) || $resolved < 1)) {
            throw new \UnexpectedValueException("Image column [{$this->name}] limit callbacks must return a positive integer or null.");
        }

        return $resolved;
    }

    private function assertDimension(int $pixels): void
    {
        if ($pixels < 1 || $pixels > 2048) {
            throw new \InvalidArgumentException('Image dimensions must be between 1 and 2048 pixels.');
        }
    }

    private function validateAlt(string $alt): string
    {
        $alt = trim($alt);
        if (mb_strlen($alt) > 500) {
            throw new \InvalidArgumentException('Image alt text must not exceed 500 characters.');
        }

        return $alt;
    }
}
