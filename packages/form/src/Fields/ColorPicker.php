<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Inlay\Forms\Field;

final class ColorPicker extends Field
{
    /** @var array<string, string> */
    private const PATTERNS = [
        'hex' => '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/',
        'rgb' => '/^rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)$/',
        'rgba' => '/^rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*(?:0|1|0?\.\d+)\s*\)$/',
        'hsl' => '/^hsl\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*\)$/',
    ];

    private string $format = 'hex';

    public function __construct(string $name)
    {
        parent::__construct($name);

        // Every colour field validates its notation, including the default.
        $this->replaceFormatRule();
    }

    protected function type(): string
    {
        return 'color-picker';
    }

    public function hex(): self
    {
        return $this->format('hex');
    }

    public function rgb(): self
    {
        return $this->format('rgb');
    }

    public function rgba(): self
    {
        return $this->format('rgba');
    }

    public function hsl(): self
    {
        return $this->format('hsl');
    }

    /**
     * Choose the notation this field exchanges.
     *
     * The browser control is a convenience; the matching pattern is registered
     * as a Laravel rule so a forged value never reaches the model.
     */
    public function format(string $format): self
    {
        if (! array_key_exists($format, self::PATTERNS)) {
            throw new \InvalidArgumentException("Unsupported colour format [{$format}].");
        }

        $this->format = $format;

        return $this->replaceFormatRule();
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'format' => $this->format,
            'pattern' => trim(self::PATTERNS[$this->format], '/'),
        ];
    }

    private function replaceFormatRule(): self
    {
        $patterns = array_map(
            static fn (string $pattern): string => 'regex:'.$pattern,
            array_values(self::PATTERNS),
        );
        $this->rules = array_values(array_filter(
            $this->rules,
            static fn (mixed $rule): bool => ! is_string($rule) || ! in_array($rule, $patterns, true),
        ));

        return $this->regex(self::PATTERNS[$this->format]);
    }
}
