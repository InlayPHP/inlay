<?php

declare(strict_types=1);

namespace Inlay\Schemas\Support;

use JsonSerializable;

final class ContentExpression implements JsonSerializable
{
    private string $prefix = '';

    private string $suffix = '';

    /** @var list<array{name: string, argument: string|int|null}> */
    private array $operators = [];

    private function __construct(
        private readonly string $type,
        private readonly ?string $path,
        private readonly ?string $template,
        private string $fallback,
    ) {}

    public static function state(string $path, string $fallback = ''): self
    {
        self::assertPath($path);

        return new self('state', $path, null, $fallback);
    }

    public static function template(string $template, string $fallback = ''): self
    {
        $placeholderPattern = '/\{\{\s*([A-Za-z_][A-Za-z0-9_-]*(?:\.(?:[A-Za-z_][A-Za-z0-9_-]*|\d+))*)\s*\}\}/';
        preg_match_all($placeholderPattern, $template, $matches);
        $remainder = preg_replace($placeholderPattern, '', $template) ?? '';

        if ($template === '' || $matches[1] === [] || str_contains($remainder, '{{') || str_contains($remainder, '}}')) {
            throw new \InvalidArgumentException('Content expression templates must contain only valid {{ state.path }} placeholders.');
        }

        foreach ($matches[1] as $path) {
            self::assertPath($path);
        }

        return new self('template', null, $template, $fallback);
    }

    /**
     * Transform the resolved value through an allow-listed operator.
     *
     * Operators are declared in PHP and applied in the browser, so the payload
     * carries a name and a bounded argument rather than anything executable.
     */
    public function upper(): self
    {
        return $this->operator('upper');
    }

    public function lower(): self
    {
        return $this->operator('lower');
    }

    public function title(): self
    {
        return $this->operator('title');
    }

    public function trim(): self
    {
        return $this->operator('trim');
    }

    public function limit(int $characters): self
    {
        if ($characters < 1 || $characters > 500) {
            throw new \InvalidArgumentException('A content expression limit must be between 1 and 500 characters.');
        }

        return $this->operator('limit', $characters);
    }

    public function number(int $decimalPlaces = 0): self
    {
        if ($decimalPlaces < 0 || $decimalPlaces > 6) {
            throw new \InvalidArgumentException('A content expression number must use 0 to 6 decimal places.');
        }

        return $this->operator('number', $decimalPlaces);
    }

    public function currency(string $currency = 'USD'): self
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('A content expression currency must be a three-letter ISO code.');
        }

        return $this->operator('currency', $currency);
    }

    private function operator(string $name, string|int|null $argument = null): self
    {
        if (count($this->operators) >= 5) {
            throw new \InvalidArgumentException('A content expression accepts at most five operators.');
        }

        $this->operators[] = ['name' => $name, 'argument' => $argument];

        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function suffix(string $suffix): self
    {
        $this->suffix = $suffix;

        return $this;
    }

    public function fallback(string $fallback): self
    {
        $this->fallback = $fallback;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'path' => $this->path,
            'template' => $this->template,
            'fallback' => $this->fallback,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
            'operators' => $this->operators,
        ];
    }

    private static function assertPath(string $path): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]*(?:\.(?:[A-Za-z_][A-Za-z0-9_-]*|\d+))*$/', $path)) {
            throw new \InvalidArgumentException('Content expression state paths must use safe dot notation.');
        }
    }
}
