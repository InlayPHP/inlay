<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use Inlay\Actions\Action;
use InvalidArgumentException;
use JsonSerializable;

abstract class Widget implements JsonSerializable
{
    protected ?string $label = null;

    protected ?string $description = null;

    protected int|string $columnSpan = 'full';

    protected int $sort = 0;

    protected bool $visible = true;

    protected ?int $pollingInterval = null;

    protected bool $lazy = false;

    /** @var list<Action> */
    private array $headerActions = [];

    /** @var list<Action> */
    private array $footerActions = [];

    protected function __construct(protected readonly string $name)
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            throw new InvalidArgumentException('A widget name must contain only lowercase letters, numbers, hyphens, and underscores.');
        }
    }

    public function label(string $label): static
    {
        $this->label = self::nonEmpty($label, 'label');

        return $this;
    }

    public function description(?string $description): static
    {
        $this->description = $description === null ? null : self::nonEmpty($description, 'description');

        return $this;
    }

    public function columnSpan(int|string $span): static
    {
        if ($span !== 'full' && (! is_int($span) || $span < 1 || $span > 12)) {
            throw new InvalidArgumentException('A widget column span must be full or an integer from 1 to 12.');
        }
        $this->columnSpan = $span;

        return $this;
    }

    public function sort(int $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    public function visible(bool $visible = true): static
    {
        $this->visible = $visible;

        return $this;
    }

    public function poll(?int $seconds): static
    {
        if ($seconds !== null && $seconds < 1) {
            throw new InvalidArgumentException('A widget polling interval must be at least one second.');
        }
        $this->pollingInterval = $seconds;

        return $this;
    }

    public function lazy(bool $lazy = true): static
    {
        $this->lazy = $lazy;

        return $this;
    }

    /** @param iterable<Action> $actions */
    public function headerActions(iterable $actions): static
    {
        $this->headerActions = $this->normalizeActions($actions, 'header');

        return $this;
    }

    /** @param iterable<Action> $actions */
    public function footerActions(iterable $actions): static
    {
        $this->footerActions = $this->normalizeActions($actions, 'footer');

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sortOrder(): int
    {
        return $this->sort;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    /** @return array<string, mixed> */
    final public function jsonSerialize(): array
    {
        return [
            'contract' => 'inlay.widgets.v1',
            'type' => $this->type(),
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'columnSpan' => $this->columnSpan,
            'sort' => $this->sort,
            'visible' => $this->visible,
            'pollingInterval' => $this->pollingInterval,
            'lazy' => $this->lazy,
            'headerActions' => $this->headerActions,
            'footerActions' => $this->footerActions,
            ...$this->payload(),
        ];
    }

    abstract protected function type(): string;

    /** @return array<string, mixed> */
    abstract protected function payload(): array;

    private static function nonEmpty(string $value, string $kind): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("A widget {$kind} cannot be empty.");
        }

        return $value;
    }

    /** @param iterable<Action> $actions */
    private function normalizeActions(iterable $actions, string $placement): array
    {
        $normalized = [];
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new InvalidArgumentException("Widget {$placement} actions must be Action instances.");
            }
            $normalized[] = $action;
        }

        return array_values($normalized);
    }
}
