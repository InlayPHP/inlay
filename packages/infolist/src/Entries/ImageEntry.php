<?php

declare(strict_types=1);

namespace Inlay\Infolists\Entries;

use Closure;
use Inlay\Infolists\Entry;
use Inlay\Support\SafeUrl;
use InvalidArgumentException;

final class ImageEntry extends Entry
{
    /** @var list<string> */
    private const REMAINING_TEXT_SIZES = ['extra-small', 'small', 'medium', 'large'];

    private string|Closure|null $url = null;

    private int|string|Closure|null $width = 40;

    private int|string|Closure|null $height = 40;

    private bool|Closure $square = false;

    private bool|Closure $circular = false;

    /** @var string|list<string|null>|Closure|null */
    private string|array|Closure|null $alt = null;

    private string|Closure|null $defaultImageUrl = null;

    private string|Closure|null $disk = null;

    private bool $diskWasConfigured = false;

    private string|Closure|null $visibility = 'private';

    private bool $visibilityWasConfigured = false;

    private bool|Closure $checkFileExistence = true;

    private bool|Closure $stacked = false;

    private int|Closure|null $ring = 3;

    private int|Closure|null $overlap = 4;

    private int|Closure|null $limit = null;

    private bool|Closure $limitedRemainingText = false;

    private string|Closure|null $limitedRemainingTextSize = 'small';

    private bool|Closure $limitedRemainingTextSeparate = false;

    /** @var array<string, scalar|null> */
    private array $extraImgAttributes = [];

    private ?Closure $extraImgAttributesUsing = null;

    protected function type(): string
    {
        return 'image-entry';
    }

    public function url(string|Closure|null $url): self
    {
        if (is_string($url)) {
            SafeUrl::from($url);
        }

        $this->url = $url;

        return $this;
    }

    /** Legacy convenience alias for independently configurable image dimensions. */
    public function size(int|string|Closure $width, int|string|Closure|null $height = null): self
    {
        if (! $width instanceof Closure) {
            $this->assertDimension($width);
        }
        if ($height !== null && ! $height instanceof Closure) {
            $this->assertDimension($height);
        }
        $this->width = $width;
        $this->height = $height ?? $width;

        return $this;
    }

    public function imageSize(int|string|Closure $pixels): self
    {
        if (! $pixels instanceof Closure) {
            $this->assertDimension($pixels);
        }

        $this->width = $pixels;
        $this->height = $pixels;

        return $this;
    }

    public function imageWidth(int|string|Closure|null $pixels): self
    {
        if ($pixels !== null && ! $pixels instanceof Closure) {
            $this->assertDimension($pixels);
        }

        $this->width = $pixels;

        return $this;
    }

    public function imageHeight(int|string|Closure|null $pixels): self
    {
        if ($pixels !== null && ! $pixels instanceof Closure) {
            $this->assertDimension($pixels);
        }

        $this->height = $pixels;

        return $this;
    }

    /**
     * the compatibility alias for imageWidth().
     *
     * @deprecated Prefer imageWidth().
     */
    public function width(int|string|Closure|null $pixels): self
    {
        return $this->imageWidth($pixels);
    }

    /**
     * the compatibility alias for imageHeight().
     *
     * @deprecated Prefer imageHeight().
     */
    public function height(int|string|Closure|null $pixels): self
    {
        return $this->imageHeight($pixels);
    }

    public function square(bool|Closure $enabled = true): self
    {
        $this->square = $enabled;

        return $this;
    }

    public function circular(bool|Closure $enabled = true): self
    {
        $this->circular = $enabled;

        return $this;
    }

    /** @param string|list<string|null>|Closure|null $alt */
    public function alt(string|array|Closure|null $alt): self
    {
        if (is_array($alt)) {
            $this->assertAltList($alt);
        }

        $this->alt = $alt;

        return $this;
    }

    public function defaultImageUrl(string|Closure|null $url): self
    {
        if (is_string($url)) {
            SafeUrl::from($url);
        }

        $this->defaultImageUrl = $url;

        return $this;
    }

    public function disk(string|Closure|null $disk): self
    {
        if (is_string($disk) && trim($disk) === '') {
            throw new InvalidArgumentException('An image entry disk cannot be empty.');
        }

        $this->disk = $disk;
        $this->diskWasConfigured = true;

        return $this;
    }

    public function visibility(string|Closure|null $visibility): self
    {
        if (is_string($visibility)) {
            $this->assertVisibility($visibility);
        }

        $this->visibility = $visibility;
        $this->visibilityWasConfigured = true;

        return $this;
    }

    public function checkFileExistence(bool|Closure $enabled = true): self
    {
        $this->checkFileExistence = $enabled;

        return $this;
    }

    public function stacked(bool|Closure $enabled = true): self
    {
        $this->stacked = $enabled;

        return $this;
    }

    public function ring(int|Closure|null $width): self
    {
        if (is_int($width)) {
            $this->assertStackValue($width, 'ring width');
        }

        $this->ring = $width;

        return $this;
    }

    public function overlap(int|Closure|null $overlap): self
    {
        if (is_int($overlap)) {
            $this->assertStackValue($overlap, 'overlap');
        }

        $this->overlap = $overlap;

        return $this;
    }

    public function limit(int|Closure|null $images = 3): self
    {
        if (is_int($images) && $images < 1) {
            throw new InvalidArgumentException('Image entry limits must be at least 1.');
        }

        $this->limit = $images;

        return $this;
    }

    public function limitedRemainingText(
        bool|Closure $enabled = true,
        bool|Closure|string|null $isSeparate = false,
        string|Closure|null $size = null,
    ): self
    {
        // Inlay accepted the legacy signature (enabled, size). Keep
        // that positional form working while exposing the API's
        // (enabled, isSeparate, size) contract.
        if (is_string($isSeparate)) {
            $size ??= $isSeparate;
            $isSeparate = false;
        }

        if ($size !== null && is_string($size)) {
            $this->assertRemainingTextSize($size);
        }

        $this->limitedRemainingText = $enabled;
        $this->limitedRemainingTextSeparate = $isSeparate ?? false;
        $this->limitedRemainingTextSize = $size ?? 'small';

        return $this;
    }

    public function limitedRemainingTextSeparate(bool|Closure $enabled = true): self
    {
        $this->limitedRemainingTextSeparate = $enabled;

        return $this;
    }

    public function limitedRemainingTextSize(string|Closure|null $size): self
    {
        if (is_string($size)) {
            $this->assertRemainingTextSize($size);
        }

        $this->limitedRemainingTextSize = $size;

        return $this;
    }

    /**
     * @param  array<string, scalar|null>|Closure  $attributes
     */
    public function extraImgAttributes(array|Closure $attributes, bool $merge = false): self
    {
        if ($attributes instanceof Closure) {
            $this->extraImgAttributesUsing = $attributes;
            if (! $merge) {
                $this->extraImgAttributes = [];
            }

            return $this;
        }

        $attributes = $this->safeImgAttributes($attributes);
        $this->extraImgAttributes = $merge
            ? [...$this->extraImgAttributes, ...$attributes]
            : $attributes;
        $this->extraImgAttributesUsing = null;

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        // Dynamic URLs are resolved while the infolist transforms each concrete
        // state value. That is what makes closures correct inside repeatables.
        $url = $this->url instanceof Closure ? null : $this->resolvedUrl($this->url, 'URL');
        $defaultImageUrl = $this->defaultImageUrl instanceof Closure ? null : $this->resolvedUrl($this->defaultImageUrl, 'default image URL');
        $width = $this->resolvedDimension($this->width, 'width');
        $height = $this->resolvedDimension($this->height, 'height');
        $ring = $this->resolvedStackValue($this->ring, 'ring width');
        $overlap = $this->resolvedStackValue($this->overlap, 'overlap');
        $limit = $this->resolvePresentationInteger($this->limit, 'image entry limit');
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Resolved image entry limits must be at least 1.');
        }

        $remainingTextSize = $this->resolvePresentationString($this->limitedRemainingTextSize, 'image entry remaining text size');
        if ($remainingTextSize !== null) {
            $this->assertRemainingTextSize($remainingTextSize);
        }

        return [
            ...parent::jsonSerialize(),
            'url' => $url,
            'width' => $width,
            'height' => $height,
            'square' => $this->resolvePresentationBoolean($this->square, 'image entry square'),
            'circular' => $this->resolvePresentationBoolean($this->circular, 'image entry circular'),
            'alt' => $this->resolvedAlt(),
            'defaultImageUrl' => $defaultImageUrl,
            'disk' => $this->disk instanceof Closure ? null : $this->resolvedDisk(),
            'visibility' => $this->visibility instanceof Closure ? null : $this->resolvedVisibility(),
            'checkFileExistence' => $this->checkFileExistence instanceof Closure ? null : $this->resolvedCheckFileExistence(),
            'stacked' => $this->resolvePresentationBoolean($this->stacked, 'image entry stacked'),
            'ring' => $ring,
            'overlap' => $overlap,
            'limit' => $limit,
            'limitedRemainingText' => $this->resolvePresentationBoolean($this->limitedRemainingText, 'image entry limited remaining text'),
            'limitedRemainingTextSeparate' => $this->resolvePresentationBoolean($this->limitedRemainingTextSeparate, 'image entry limited remaining text separate'),
            'limitedRemainingTextSize' => $remainingTextSize,
            'extraImgAttributes' => $this->resolvedExtraImgAttributes(),
        ];
    }

    private function assertDimension(int|string $pixels): void
    {
        if (is_int($pixels) && ($pixels < 1 || $pixels > 2048)) {
            throw new InvalidArgumentException('Image entry dimensions must be between 1 and 2048 pixels.');
        }
        if (is_string($pixels) && preg_match('/^(?:auto|\d+(?:\.\d+)?(?:px|rem|em|%|vw|vh|vmin|vmax|ch|ex))$/', trim($pixels)) !== 1) {
            throw new InvalidArgumentException('Image entry dimensions must be a pixel value or a safe CSS length.');
        }
    }

    /** @param array<array-key, mixed> $alt */
    private function assertAltList(array $alt): void
    {
        if (! array_is_list($alt)) {
            throw new InvalidArgumentException('Image entry alt text lists must be numerically indexed.');
        }

        foreach ($alt as $value) {
            if (! is_string($value) && $value !== null) {
                throw new InvalidArgumentException('Image entry alt text lists must contain strings or null values.');
            }
        }
    }

    /** @return string|list<string|null>|null */
    private function resolvedAlt(): string|array|null
    {
        $resolved = $this->evaluate($this->alt);
        if ($resolved === null || is_string($resolved)) {
            return $resolved;
        }
        if (! is_array($resolved)) {
            throw new \UnexpectedValueException('Image entry alt callbacks must return a string, a list of strings or null.');
        }

        $this->assertAltList($resolved);

        return array_values($resolved);
    }

    private function resolvedDimension(int|string|Closure|null $value, string $property): int|string|null
    {
        $resolved = $this->evaluate($value);
        if ($resolved === null) {
            return null;
        }
        if (! is_int($resolved) && ! is_string($resolved)) {
            throw new \UnexpectedValueException("Image entry {$property} callbacks must return an integer or safe CSS length.");
        }
        $this->assertDimension($resolved);

        return is_string($resolved) ? trim($resolved) : $resolved;
    }

    private function assertStackValue(int $value, string $property): void
    {
        if ($value < 0 || $value > 8) {
            throw new InvalidArgumentException("Image entry stack {$property} must be between 0 and 8.");
        }
    }

    private function resolvedStackValue(int|Closure|null $value, string $property): ?int
    {
        $resolved = $this->resolvePresentationInteger($value, "image entry stack {$property}");
        if ($resolved !== null) {
            $this->assertStackValue($resolved, $property);
        }

        return $resolved;
    }

    private function assertRemainingTextSize(string $size): void
    {
        if (! in_array($size, self::REMAINING_TEXT_SIZES, true)) {
            throw new InvalidArgumentException("Unsupported image entry remaining text size [{$size}].");
        }
    }

    private function resolvedUrl(string|Closure|null $value, string $property): ?string
    {
        $resolved = $this->resolvePresentationString($value, "image entry {$property}");

        return $resolved === null ? null : SafeUrl::from($resolved)->value();
    }

    /**
     * Resolve state-sensitive image configuration for the server transformer.
     *
     * @return array{url: ?string, defaultImageUrl: ?string, disk: ?string, visibility: string, checkFileExistence: bool, storageExplicit: bool}
     * @internal
     */
    public function imageStateConfiguration(mixed $state): array
    {
        $url = $this->resolveStateUrl($this->url, 'URL', $state);
        $defaultImageUrl = $this->resolveStateUrl($this->defaultImageUrl, 'default image URL', $state);

        return [
            'url' => $url,
            'defaultImageUrl' => $defaultImageUrl,
            'disk' => $this->resolvedDisk($state),
            'visibility' => $this->resolvedVisibility($state),
            'checkFileExistence' => $this->resolvedCheckFileExistence($state),
            'storageExplicit' => $this->diskWasConfigured || $this->visibilityWasConfigured,
        ];
    }

    /** @internal */
    public function hasDynamicImageConfiguration(): bool
    {
        return $this->url instanceof Closure
            || $this->defaultImageUrl instanceof Closure
            || $this->disk instanceof Closure
            || $this->visibility instanceof Closure
            || $this->checkFileExistence instanceof Closure;
    }

    private function resolveStateUrl(string|Closure|null $value, string $property, mixed $state): ?string
    {
        $resolved = $value instanceof Closure
            ? $this->evaluate($value, ['state' => $state], positional: [$state])
            : $value;
        if ($resolved === null) {
            return null;
        }
        if (! is_string($resolved)) {
            throw new \UnexpectedValueException("Image entry {$property} callbacks must return a string or null.");
        }

        return SafeUrl::from($resolved)->value();
    }

    private function resolvedDisk(mixed $state = null): ?string
    {
        $resolved = $this->disk instanceof Closure
            ? $this->evaluate($this->disk, ['state' => $state], positional: [$state])
            : $this->disk;
        if ($resolved === null) {
            return null;
        }
        if (! is_string($resolved) || trim($resolved) === '') {
            throw new \UnexpectedValueException('Image entry disk callbacks must return a non-empty string or null.');
        }

        return trim($resolved);
    }

    private function resolvedVisibility(mixed $state = null): string
    {
        $resolved = $this->visibility instanceof Closure
            ? $this->evaluate($this->visibility, ['state' => $state], positional: [$state])
            : $this->visibility;
        if ($resolved === null) {
            return $this->resolvedDisk($state) === 'public' ? 'public' : 'private';
        }
        if (! is_string($resolved)) {
            throw new \UnexpectedValueException('Image entry visibility callbacks must return a string or null.');
        }
        $this->assertVisibility($resolved);

        return $resolved;
    }

    private function resolvedCheckFileExistence(mixed $state = null): bool
    {
        $resolved = $this->checkFileExistence instanceof Closure
            ? $this->evaluate($this->checkFileExistence, ['state' => $state], positional: [$state])
            : $this->checkFileExistence;
        if (! is_bool($resolved)) {
            throw new \UnexpectedValueException('Image entry existence-check callbacks must return a boolean.');
        }

        return $resolved;
    }

    private function assertVisibility(string $visibility): void
    {
        if (! in_array($visibility, ['public', 'private'], true)) {
            throw new InvalidArgumentException("Unsupported image entry visibility [{$visibility}].");
        }
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, string>
     */
    private function safeImgAttributes(array $attributes): array
    {
        $unsafe = ['children', 'dangerouslysetinnerhtml', 'innerhtml', 'textcontent', 'key', 'ref', 'style', 'src', 'srcdoc', 'srcset', 'formaction', 'action', 'xlink:href'];
        $safe = [];

        foreach ($attributes as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $key) !== 1) {
                throw new InvalidArgumentException('Image entry attribute names must be simple HTML attribute names.');
            }
            if (str_starts_with(strtolower($key), 'on') || in_array(strtolower($key), $unsafe, true)) {
                throw new InvalidArgumentException("Image entry attribute [{$key}] is not allowed.");
            }
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException("Image entry attribute [{$key}] must be a scalar or null.");
            }

            $safe[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $safe;
    }

    /** @return array<string, string> */
    private function resolvedExtraImgAttributes(): array
    {
        if ($this->extraImgAttributesUsing === null) {
            return $this->extraImgAttributes;
        }

        $resolved = $this->evaluate($this->extraImgAttributesUsing);
        if (! is_array($resolved)) {
            throw new \UnexpectedValueException('Image entry attribute callbacks must return an array.');
        }

        return [...$this->extraImgAttributes, ...$this->safeImgAttributes($resolved)];
    }
}
