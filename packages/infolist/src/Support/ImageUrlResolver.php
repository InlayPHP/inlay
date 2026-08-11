<?php

declare(strict_types=1);

namespace Inlay\Infolists\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Inlay\Infolists\Entries\ImageEntry;
use Inlay\Support\SafeUrl;
use RuntimeException;

/** @internal Converts storage-relative image state into browser-loadable URLs. */
final class ImageUrlResolver
{
    /** @var array<string, Filesystem> */
    private array $disks = [];

    public function __construct(
        private readonly ?FilesystemFactory $filesystems,
        private readonly DateTimeInterface $temporaryUrlExpiration = new DateTimeImmutable('+5 minutes'),
    ) {
    }

    public function resolve(ImageEntry $entry, mixed $state): mixed
    {
        $configuration = null;
        if (! $entry->hasDynamicImageConfiguration()) {
            $configuration = $entry->imageStateConfiguration($state);
        }
        if ($configuration !== null && $configuration['url'] !== null) {
            return $configuration['url'];
        }

        $values = is_array($state) && array_is_list($state) ? $state : [$state];
        $resolved = [];
        $fallbacks = [];
        foreach ($values as $value) {
            // The resolver evaluates URL, disk, visibility, and existence callbacks
            // against each collection item. This also keeps top-level galleries
            // consistent with the existing repeatable-row behavior.
            $itemConfiguration = $entry->imageStateConfiguration($value);
            $url = $itemConfiguration['url'] ?? $this->resolveValue($value, $itemConfiguration);
            if ($url !== null) {
                $resolved[] = $url;
            } elseif ($itemConfiguration['defaultImageUrl'] !== null) {
                $fallbacks[] = $itemConfiguration['defaultImageUrl'];
            }
        }

        if ($values === []) {
            $configuration ??= $entry->imageStateConfiguration($state);
            if ($configuration['defaultImageUrl'] !== null) {
                $fallbacks[] = $configuration['defaultImageUrl'];
            }
        }

        if ($resolved === [] && $fallbacks !== []) {
            // Preserve Inlay's existing one-fallback behavior when a whole
            // collection is empty or every source is unavailable.
            $resolved[] = $fallbacks[0];
        }

        if (is_array($state) && array_is_list($state)) {
            return $resolved;
        }

        return $resolved[0] ?? null;
    }

    /**
     * @param array{disk: ?string, visibility: string, checkFileExistence: bool, storageExplicit: bool} $configuration
     */
    private function resolveValue(mixed $value, array $configuration): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if ($this->looksLikeBrowserUrl($value)) {
            return $this->safeStateUrl($value);
        }

        if ($this->filesystems === null) {
            if ($configuration['storageExplicit']) {
                throw new RuntimeException('ImageEntry storage resolution requires Laravel\'s filesystem factory. Bind Illuminate\\Contracts\\Filesystem\\Factory or pass one to Infolist::filesystem().');
            }

            // Preserve pre-storage-package behavior outside a Laravel app.
            return $value;
        }

        $disk = $this->disk($configuration['disk']);
        if ($configuration['checkFileExistence'] && ! $disk->exists($value)) {
            return null;
        }

        $url = $configuration['visibility'] === 'public'
            ? $this->publicUrl($disk, $value)
            : $this->temporaryUrl($disk, $value);

        try {
            return SafeUrl::from($url)->value();
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException('The configured image filesystem generated an unsafe URL.', previous: $exception);
        }
    }

    private function looksLikeBrowserUrl(string $value): bool
    {
        return str_starts_with($value, '/')
            || preg_match('#^[\\\\/]{2}#', $value) === 1
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) === 1;
    }

    private function safeStateUrl(string $value): ?string
    {
        try {
            $url = SafeUrl::from($value)->value();
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches) === 1
            && ! in_array(strtolower($matches[1]), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private function disk(?string $name): Filesystem
    {
        $key = $name ?? '__default__';

        return $this->disks[$key] ??= $this->filesystems->disk($name);
    }

    private function publicUrl(Filesystem $disk, string $path): string
    {
        if (! method_exists($disk, 'url')) {
            throw new RuntimeException('The configured public image filesystem cannot generate URLs.');
        }

        $url = $disk->url($path);
        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('The configured public image filesystem returned an invalid URL.');
        }

        return $url;
    }

    private function temporaryUrl(Filesystem $disk, string $path): string
    {
        if (! method_exists($disk, 'temporaryUrl')) {
            throw new RuntimeException('The configured private image filesystem cannot generate temporary URLs. Use visibility(\'public\') or a driver with temporary URL support.');
        }

        $url = $disk->temporaryUrl($path, $this->temporaryUrlExpiration);
        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('The configured private image filesystem returned an invalid temporary URL.');
        }

        return $url;
    }
}
