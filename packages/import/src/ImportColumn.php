<?php

declare(strict_types=1);

namespace Inlay\Imports;

use Closure;
use InvalidArgumentException;
use JsonSerializable;

final class ImportColumn implements JsonSerializable
{
    /** @var list<string> */
    private array $aliases = [];

    private bool $requiredMapping = false;

    private ?Closure $caster = null;

    private function __construct(
        private readonly string $name,
        private string $label,
    ) {}

    public static function make(string $name): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('An import column name cannot be empty.');
        }

        return new self($name, ucfirst(str_replace(['_', '-'], ' ', $name)));
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function aliases(string ...$aliases): self
    {
        $this->aliases = array_values(array_unique([
            ...$this->aliases,
            ...array_filter(array_map('trim', $aliases)),
        ]));

        return $this;
    }

    public function requiredMapping(bool $required = true): self
    {
        $this->requiredMapping = $required;

        return $this;
    }

    /** @param callable(mixed, array<string, mixed>, array<string, mixed>): mixed $caster */
    public function castUsing(callable $caster): self
    {
        $this->caster = Closure::fromCallable($caster);

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function labelText(): string
    {
        return $this->label;
    }

    /** @return list<string> */
    public function aliasesList(): array
    {
        return $this->aliases;
    }

    public function isMappingRequired(): bool
    {
        return $this->requiredMapping;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $options
     */
    public function cast(mixed $value, array $row, array $options): mixed
    {
        return $this->caster === null ? $value : ($this->caster)($value, $row, $options);
    }

    /** @return array{name: string, label: string, aliases: list<string>, requiredMapping: bool} */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'aliases' => $this->aliases,
            'requiredMapping' => $this->requiredMapping,
        ];
    }
}
