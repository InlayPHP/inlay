<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use Inlay\Widgets\Contracts\ProvidesWidgets;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Finds request-aware widget providers in an application namespace.
 *
 * Discovery only caches the class list for the current PHP process. Provider
 * output is still resolved for every request, so user-specific widget data is
 * never accidentally shared between dashboard visitors.
 */
final class WidgetDiscovery
{
    /** @var array<string, list<class-string<ProvidesWidgets>>> */
    private static array $cache = [];

    /**
     * @param string|iterable<string> $directories
     * @return list<class-string<ProvidesWidgets>>
     */
    public function discover(string|iterable $directories, string $namespace, bool $cache = true): array
    {
        $namespace = trim($namespace, " \\t\\n\\r\\0\\x0B\\\\");
        if ($namespace === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace) !== 1) {
            throw new InvalidArgumentException('A widget discovery namespace must be a valid PHP namespace.');
        }

        $paths = is_string($directories) ? [$directories] : [...$directories];
        $paths = array_values(array_unique(array_map(static function (mixed $path): string {
            if (! is_string($path) || trim($path) === '') {
                throw new InvalidArgumentException('Widget discovery directories must be non-empty strings.');
            }

            $resolved = realpath($path);
            if ($resolved === false || ! is_dir($resolved)) {
                throw new InvalidArgumentException("Widget discovery directory [{$path}] does not exist.");
            }

            return rtrim($resolved, DIRECTORY_SEPARATOR);
        }, $paths)));

        sort($paths);
        $key = $namespace.'|'.implode('|', $paths);
        if ($cache && array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $providers = [];
        foreach ($paths as $path) {
            $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($directory);

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $relative = ltrim(str_replace($path, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                $class = $namespace.'\\'.str_replace(
                    [DIRECTORY_SEPARATOR, '.php'],
                    ['\\', ''],
                    $relative,
                );

                if (! class_exists($class) || ! is_subclass_of($class, ProvidesWidgets::class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
                if (! $reflection->isAbstract()) {
                    $providers[] = $class;
                }
            }
        }

        $providers = array_values(array_unique($providers));
        sort($providers);

        if ($cache) {
            self::$cache[$key] = $providers;
        }

        return $providers;
    }

    public static function clear(): void
    {
        self::$cache = [];
    }
}
