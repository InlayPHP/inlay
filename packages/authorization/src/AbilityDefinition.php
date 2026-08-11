<?php

declare(strict_types=1);

namespace Inlay\Authorization;

use InvalidArgumentException;
use JsonSerializable;

final class AbilityDefinition implements JsonSerializable
{
    private ?string $description = null;

    private bool $dangerous = false;

    private function __construct(
        private readonly string $name,
        private string $label,
        private string $group,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*(?:\.[A-Za-z][A-Za-z0-9_-]*)+$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid authorization ability [{$name}].");
        }
    }

    public static function make(string $name): self
    {
        $parts = explode('.', $name);
        $ability = array_pop($parts);
        $group = implode(' ', $parts);

        return new self($name, self::headline($ability), self::headline($group));
    }

    public function label(string $label): self
    {
        $this->label = self::required($label, 'label');

        return $this;
    }

    public function group(string $group): self
    {
        $this->group = self::required($group, 'group');

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description === null ? null : self::required($description, 'description');

        return $this;
    }

    public function dangerous(bool $dangerous = true): self
    {
        $this->dangerous = $dangerous;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array{name: string, label: string, group: string, description: string|null, dangerous: bool} */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'group' => $this->group,
            'description' => $this->description,
            'dangerous' => $this->dangerous,
        ];
    }

    private static function headline(string $value): string
    {
        return ucwords(trim((string) preg_replace('/[_-]+|(?<=[a-z])(?=[A-Z])/', ' ', $value)));
    }

    private static function required(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("An ability {$field} cannot be empty.");
        }

        return $value;
    }
}
