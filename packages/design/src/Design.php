<?php

declare(strict_types=1);

namespace Inlay\Design;

use Inlay\Theme\Theme;
use InvalidArgumentException;

/**
 * Public design-system entry point.
 *
 * Theme remains the serializable contract used by panel and renderer packages.
 * Design adds the stable place for application themes and CSS generation without
 * making every package know how the contract is translated into stylesheets.
 */
final class Design
{
    public static function base(): Theme
    {
        return Theme::base();
    }

    public static function default(): Theme
    {
        return Theme::default();
    }

    public static function orbit(): Theme
    {
        return Theme::orbit();
    }

    public static function highContrast(): Theme
    {
        return Theme::highContrast();
    }

    public static function make(string $name = 'custom'): Theme
    {
        return Theme::make($name);
    }

    /**
     * Render a theme contract as a deterministic, portable CSS stylesheet.
     *
     * Dark tokens are emitted both for OS preference and an explicit
     * data-theme="dark" shell, so applications can support either strategy.
     */
    public static function css(Theme $theme): string
    {
        $light = self::variables($theme->light());
        $dark = self::variables($theme->dark());
        $lines = [':root {'];

        foreach ($light as $name => $value) {
            $lines[] = "    {$name}: {$value};";
        }

        $lines[] = '}';

        if ($dark !== []) {
            $lines[] = '';
            $lines[] = '@media (prefers-color-scheme: dark) {';
            $lines[] = '    :root:not([data-theme="light"]) {';
            foreach ($dark as $name => $value) {
                $lines[] = "        {$name}: {$value};";
            }
            $lines[] = '    }';
            $lines[] = '}';
            $lines[] = '';
            $lines[] = '[data-theme="dark"] {';
            foreach ($dark as $name => $value) {
                $lines[] = "    {$name}: {$value};";
            }
            $lines[] = '}';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Convert a token map to CSS custom property names and safe scalar values.
     *
     * @param array<string, scalar|null> $tokens
     * @return array<string, string>
     */
    public static function variables(array $tokens): array
    {
        $variables = [];

        foreach ($tokens as $name => $value) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
                throw new InvalidArgumentException("Invalid theme token [{$name}].");
            }

            if ($value === null) {
                continue;
            }

            if (! is_scalar($value)) {
                throw new InvalidArgumentException("Theme token [{$name}] must be scalar or null.");
            }

            $string = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if (preg_match('/[\r\n;{}]|<\\/style/i', $string) === 1) {
                throw new InvalidArgumentException("Theme token [{$name}] contains characters that cannot be emitted as CSS.");
            }

            $variables['--inlay-'.$name] = $string;
        }

        return $variables;
    }
}
