<?php

declare(strict_types=1);

namespace Inlay\Core;

use InvalidArgumentException;
use JsonSerializable;

final readonly class Asset implements JsonSerializable
{
    public const SCRIPT = 'script';

    public const STYLE = 'style';

    public string $kind;

    /**
     * @deprecated Use $kind. Kept for source compatibility with Inlay 0.2 callers.
     */
    public string $type;

    /** @param array<string, string|bool> $attributes */
    public function __construct(
        public string $id,
        public string $source,
        string $kind,
        public bool $lazy = false,
        public array $attributes = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[-:\/][a-z0-9]+)*$/', $id) !== 1) {
            throw new InvalidArgumentException("Invalid asset ID [{$id}].");
        }

        if (trim($source) === '') {
            throw new InvalidArgumentException('An asset source cannot be empty.');
        }

        if (! in_array($kind, [self::SCRIPT, self::STYLE], true)) {
            throw new InvalidArgumentException("Unsupported asset kind [{$kind}].");
        }

        foreach ($attributes as $name => $value) {
            if (! is_string($name)
                || preg_match('/^[A-Za-z_:][A-Za-z0-9:._-]*$/', $name) !== 1
                || str_starts_with(strtolower($name), 'on')) {
                throw new InvalidArgumentException("Unsafe asset attribute name [{$name}].");
            }

            if (! is_string($value) && ! is_bool($value)) {
                throw new InvalidArgumentException("Asset attribute [{$name}] must be a string or boolean.");
            }
        }

        $this->kind = $kind;
        $this->type = $kind;
    }

    /** @param array<string, string|bool> $attributes */
    public static function script(string $id, string $source, bool $lazy = false, array $attributes = []): self
    {
        return new self($id, $source, self::SCRIPT, $lazy, $attributes);
    }

    /** @param array<string, string|bool> $attributes */
    public static function style(string $id, string $source, bool $lazy = false, array $attributes = []): self
    {
        return new self($id, $source, self::STYLE, $lazy, $attributes);
    }

    /** @return array{id: string, source: string, kind: string, lazy: bool, attributes: array<string, string|bool>} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'kind' => $this->kind,
            'lazy' => $this->lazy,
            'attributes' => $this->attributes,
        ];
    }
}
