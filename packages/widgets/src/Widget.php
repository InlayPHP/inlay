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

    private ?int $columnStart = null;

    private ?string $tab = null;

    private ?string $tabLabel = null;

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

    /** Start this widget at a specific twelve-column grid position. */
    public function columnStart(?int $start): static
    {
        if ($start !== null && ($start < 1 || $start > 12)) {
            throw new InvalidArgumentException('A widget column start must be null or an integer from 1 to 12.');
        }

        $this->columnStart = $start;

        return $this;
    }

    /** Place this widget in a named PHP-defined dashboard tab. */
    public function tab(string $name, ?string $label = null): static
    {
        $name = trim($name);
        if (! preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new InvalidArgumentException('A widget tab name must contain only lowercase letters, numbers, hyphens, and underscores.');
        }

        if ($label !== null && trim($label) === '') {
            throw new InvalidArgumentException('A widget tab label cannot be empty.');
        }

        $this->tab = $name;
        $this->tabLabel = $label === null ? null : trim($label);

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

    public function tabName(): ?string
    {
        return $this->tab;
    }

    public function tabLabel(): ?string
    {
        return $this->tabLabel;
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
            'columnStart' => $this->columnStart,
            'sort' => $this->sort,
            'visible' => $this->visible,
            'pollingInterval' => $this->pollingInterval,
            'lazy' => $this->lazy,
            'tab' => $this->tab,
            'tabLabel' => $this->tabLabel,
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
