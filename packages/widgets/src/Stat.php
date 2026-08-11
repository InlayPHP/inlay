<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use Inlay\Support\SafeUrl;
use InvalidArgumentException;
use JsonSerializable;

final class Stat implements JsonSerializable
{
    private ?string $description = null;

    private ?string $icon = null;

    private string $color = 'primary';

    private ?string $url = null;

    private ?string $trend = null;

    /** @var list<int|float> */
    private array $chart = [];

    private function __construct(private readonly string $label, private readonly string|int|float $value)
    {
        if (trim($label) === '') {
            throw new InvalidArgumentException('A stat label cannot be empty.');
        }
    }

    public static function make(string $label, string|int|float $value): self
    {
        return new self($label, $value);
    }

    public function description(string $description): self
    {
        $this->description = trim($description);

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = trim($icon);

        return $this;
    }

    public function color(string $color): self
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $color)) {
            throw new InvalidArgumentException('A stat color must be a semantic token name.');
        }
        $this->color = $color;

        return $this;
    }

    public function url(?string $url): self
    {
        if ($url !== null) {
            try {
                SafeUrl::from($url);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('A stat URL must use a safe application or web URL.');
            }
        }
        $this->url = $url;

        return $this;
    }

    public function trend(?string $trend): self
    {
        if ($trend !== null && ! in_array($trend, ['up', 'down', 'flat'], true)) {
            throw new InvalidArgumentException('A stat trend must be up, down, flat, or null.');
        }
        $this->trend = $trend;

        return $this;
    }

    /** @param list<int|float> $values */
    public function chart(array $values): self
    {
        foreach ($values as $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException('Stat chart values must be numeric.');
            }
        }
        $this->chart = array_values($values);

        return $this;
    }

    public function jsonSerialize(): array
    {
        return ['label' => $this->label, 'value' => $this->value, 'description' => $this->description, 'icon' => $this->icon, 'color' => $this->color, 'url' => $this->url, 'trend' => $this->trend, 'chart' => $this->chart];
    }
}
