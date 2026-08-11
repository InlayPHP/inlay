<?php

declare(strict_types=1);

namespace Inlay\Theme;

use InvalidArgumentException;
use JsonSerializable;

final class Theme implements JsonSerializable
{
    /** @var array<string, scalar|null> */
    private array $tokens = [];

    /** @var array<string, scalar|null> */
    private array $darkTokens = [];

    private function __construct(private readonly string $name) {}

    public static function make(string $name = 'custom'): self
    {
        $name = trim($name);
        if ($name === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $name)) {
            throw new InvalidArgumentException('A theme name must contain only letters, numbers, hyphens, and underscores.');
        }

        return new self($name);
    }

    public static function base(): self
    {
        return self::make('base')->tokens([
            'accent' => '#18181b',
            'accent-foreground' => '#ffffff',
            'background' => '#fafafa',
            'surface' => '#ffffff',
            'surface-muted' => '#f4f4f5',
            'foreground' => '#18181b',
            'muted' => '#71717a',
            'border' => 'rgb(24 24 27 / 0.12)',
            // Keep controls close to the API's zinc-300 default instead of a
            // high-contrast black outline. Applications can still override this
            // token per theme when they need stronger or softer borders.
            'control-border' => '#d4d4d8',
            'hover' => '#f4f4f5',
            'badge' => '#e4e4e7',
            'danger' => '#dc2626',
            'danger-surface' => 'rgb(220 38 38 / 0.08)',
            'success' => '#16a34a',
            'success-surface' => 'rgb(22 163 74 / 0.08)',
            'warning' => '#d97706',
            'warning-surface' => 'rgb(217 119 6 / 0.1)',
            'info' => '#0284c7',
            'info-surface' => 'rgb(2 132 199 / 0.08)',
            'overlay' => 'rgb(24 24 27 / 0.55)',
            'scrim' => 'rgb(0 0 0 / 0.3)',
            'radius' => '0.5rem',
            'control-height' => '2.5rem',
            'button-height' => '2.5rem',
            'button-xs-height' => '2rem',
            'button-sm-height' => '2.25rem',
            'button-lg-height' => '2.75rem',
            'icon-button-size' => '2.5rem',
            // Shared recipe tokens keep spacing, typography, focus, and motion
            // consistent across controls and package-owned surfaces. Applications
            // can override these once in their generated theme.
            'space-control-x' => '0.75rem',
            'space-control-y' => '0.5rem',
            'space-button-x' => '0.75rem',
            'space-button-y' => '0.375rem',
            'space-card' => '1.25rem',
            'space-dialog' => '1.25rem',
            'space-menu-x' => '0.625rem',
            'space-menu-y' => '0.5rem',
            'space-table-x' => '0.75rem',
            'space-table-y' => '0.75rem',
            'space-stack' => '0.75rem',
            'space-inline' => '0.5rem',
            'space-field' => '0.375rem',
            'font-size-body' => '0.875rem',
            'font-size-control' => '1rem',
            'font-size-label' => '0.875rem',
            'font-size-caption' => '0.75rem',
            'font-size-heading' => '1.125rem',
            'font-size-title' => '1.5rem',
            'line-height-body' => '1.5',
            'line-height-control' => '1.5',
            'line-height-tight' => '1.25',
            'font-weight-label' => '500',
            'font-weight-heading' => '600',
            // Resolve through the active accent alias so a dark-mode accent
            // automatically updates the focus ring unless explicitly set.
            'focus-ring-color' => 'var(--inlay-accent)',
            'focus-ring-width' => '2px',
            'focus-ring-offset' => '0px',
            'motion-duration' => '160ms',
            'motion-duration-fast' => '120ms',
            'motion-duration-slow' => '240ms',
            'motion-easing' => 'cubic-bezier(0.2, 0, 0, 1)',
            'sidebar-width' => '17rem',
            'collapsed-sidebar-width' => '4.5rem',
            'font-family' => 'ui-sans-serif, system-ui, sans-serif',
            'shadow' => '0 1px 2px rgb(0 0 0 / 0.05)',
        ])->darkTokens([
            'background' => '#09090b',
            'surface' => '#18181b',
            'surface-muted' => '#27272a',
            'foreground' => '#fafafa',
            'muted' => '#a1a1aa',
            'border' => 'rgb(255 255 255 / 0.12)',
            'control-border' => 'rgb(255 255 255 / 0.2)',
            'hover' => '#27272a',
            'badge' => '#3f3f46',
            'danger' => '#f87171',
            'danger-surface' => 'rgb(248 113 113 / 0.12)',
            'success' => '#4ade80',
            'success-surface' => 'rgb(74 222 128 / 0.12)',
            'warning' => '#fbbf24',
            'warning-surface' => 'rgb(251 191 36 / 0.14)',
            'info' => '#38bdf8',
            'info-surface' => 'rgb(56 189 248 / 0.12)',
            'overlay' => 'rgb(0 0 0 / 0.65)',
            'scrim' => 'rgb(0 0 0 / 0.55)',
        ]);
    }

    public static function default(): self
    {
        return self::base()->named('default')->tokens([
            'accent' => '#4f46e5',
            'background' => '#f6f7fb',
            'surface-muted' => '#f4f4f5',
            'radius' => '0.75rem',
            'shadow' => '0 1px 2px rgb(15 23 42 / 0.06), 0 1px 3px rgb(15 23 42 / 0.08)',
        ])->darkTokens([
            'accent' => '#818cf8',
            'accent-foreground' => '#111827',
            'background' => '#09090b',
            'surface' => '#18181b',
            'surface-muted' => '#242427',
        ]);
    }

    public static function highContrast(): self
    {
        return self::base()->named('high-contrast')->tokens([
            'accent' => '#0047ab',
            'accent-foreground' => '#ffffff',
            'background' => '#ffffff',
            'surface' => '#ffffff',
            'surface-muted' => '#f5f5f5',
            'foreground' => '#000000',
            'muted' => '#404040',
            'border' => '#404040',
            'control-border' => '#404040',
            'hover' => '#e5e5e5',
            'badge' => '#d4d4d4',
            'danger' => '#b00020',
            'danger-surface' => '#fff0f0',
            'success' => '#006b2f',
            'success-surface' => '#effcf3',
            'warning' => '#7a4100',
            'warning-surface' => '#fff8e8',
            'info' => '#005a8c',
            'info-surface' => '#eff9ff',
            'radius' => '0.375rem',
            'control-height' => '2.75rem',
            'button-height' => '2.75rem',
            'button-xs-height' => '2.25rem',
            'button-sm-height' => '2.5rem',
            'button-lg-height' => '3rem',
            'icon-button-size' => '2.75rem',
            'shadow' => '0 0 0 1px rgb(0 0 0 / 0.2)',
        ])->darkTokens([
            'accent' => '#93c5fd',
            'accent-foreground' => '#000000',
            'background' => '#000000',
            'surface' => '#0a0a0a',
            'surface-muted' => '#1f1f1f',
            'foreground' => '#ffffff',
            'muted' => '#d4d4d4',
            'border' => '#e5e5e5',
            'control-border' => '#e5e5e5',
            'hover' => '#2d2d2d',
            'badge' => '#525252',
            'danger-surface' => '#3d1717',
            'success-surface' => '#12321e',
            'warning-surface' => '#3a2a0c',
            'info-surface' => '#102f3d',
            'overlay' => 'rgb(0 0 0 / 0.75)',
            'scrim' => 'rgb(0 0 0 / 0.7)',
            'danger' => '#ff8080',
            'success' => '#86efac',
            'warning' => '#fcd34d',
            'info' => '#7dd3fc',
        ]);
    }

    public function named(string $name): self
    {
        $copy = self::make($name);
        $copy->tokens = $this->tokens;
        $copy->darkTokens = $this->darkTokens;

        return $copy;
    }

    /** @param array<string, scalar|null> $tokens */
    public function tokens(array $tokens): self
    {
        $this->tokens = [...$this->tokens, ...self::validated($tokens)];

        return $this;
    }

    /** @param array<string, scalar|null> $tokens */
    public function darkTokens(array $tokens): self
    {
        $this->darkTokens = [...$this->darkTokens, ...self::validated($tokens)];

        return $this;
    }

    public function accent(string $color, ?string $foreground = null): self
    {
        $this->tokens(['accent' => $color]);
        if ($foreground !== null) {
            $this->tokens(['accent-foreground' => $foreground]);
        }

        return $this;
    }

    public function radius(string $radius): self
    {
        return $this->tokens(['radius' => $radius]);
    }

    public function font(string $family): self
    {
        return $this->tokens(['font-family' => $family]);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, scalar|null> */
    public function light(): array
    {
        return $this->tokens;
    }

    /** @return array<string, scalar|null> */
    public function dark(): array
    {
        return $this->darkTokens;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'contract' => 'inlay.themes.v1',
            'name' => $this->name,
            'tokens' => (object) $this->tokens,
            'darkTokens' => (object) $this->darkTokens,
        ];
    }

    /** @param array<string, scalar|null> $tokens @return array<string, scalar|null> */
    private static function validated(array $tokens): array
    {
        foreach ($tokens as $name => $value) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
                throw new InvalidArgumentException("Invalid theme token [{$name}].");
            }
            if (is_string($value) && trim($value) === '') {
                throw new InvalidArgumentException("Theme token [{$name}] cannot be empty.");
            }
        }

        return $tokens;
    }
}
