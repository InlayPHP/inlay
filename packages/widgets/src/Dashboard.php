<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use Inlay\Actions\Action;
use InvalidArgumentException;

/** PHP-owned presentation metadata for a panel dashboard. */
final class Dashboard
{
    private ?string $eyebrow = null;

    private ?string $heading = null;

    private ?string $description = null;

    /** @var list<Action> */
    private array $headerActions = [];

    public static function make(): self
    {
        return new self;
    }

    public function eyebrow(?string $eyebrow): self
    {
        $this->eyebrow = self::optionalText($eyebrow, 'eyebrow');

        return $this;
    }

    public function heading(?string $heading): self
    {
        $this->heading = self::optionalText($heading, 'heading');

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = self::optionalText($description, 'description');

        return $this;
    }

    /** @param iterable<Action> $actions */
    public function headerActions(iterable $actions): self
    {
        $this->headerActions = [];
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new InvalidArgumentException('Dashboard header actions must be Action instances.');
            }
            $this->headerActions[] = $action;
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'eyebrow' => $this->eyebrow,
            'heading' => $this->heading,
            'description' => $this->description,
            'headerActions' => $this->headerActions,
        ];
    }

    private static function optionalText(?string $value, string $name): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("A dashboard {$name} cannot be empty.");
        }

        return $value;
    }
}
