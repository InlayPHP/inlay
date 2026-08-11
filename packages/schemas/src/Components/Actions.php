<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Closure;
use Inlay\Actions\Action;
use Inlay\Schemas\Component;

final class Actions extends Component
{
    private const ALIGNMENTS = ['start', 'center', 'end', 'between'];

    /** @var list<Action>|Closure */
    private array|Closure $actions = [];

    private string|Closure $alignment = 'start';

    /** @param list<Action>|Closure $actions */
    public static function make(string $name = 'actions', array|Closure $actions = []): static
    {
        return (new static($name))->actions($actions);
    }

    protected function type(): string
    {
        return 'actions';
    }

    /** @param list<Action>|Closure $actions */
    public function actions(array|Closure $actions): self
    {
        $this->actions = $actions instanceof Closure ? $actions : $this->validateActions($actions);

        return $this;
    }

    public function alignment(string|Closure $alignment): self
    {
        $this->alignment = $alignment instanceof Closure ? $alignment : $this->validateAlignment($alignment);

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'actions' => $this->resolveActions(),
            'alignment' => $this->resolveAlignment(),
        ];
    }

    /** @return list<Action> */
    private function resolveActions(): array
    {
        if (! $this->actions instanceof Closure) {
            return $this->actions;
        }

        $resolved = $this->evaluate($this->actions);
        if (! is_array($resolved) || ($resolved !== [] && ! array_is_list($resolved))) {
            throw new \UnexpectedValueException('Schema action callbacks must return a list of actions.');
        }

        return $this->validateActions($resolved);
    }

    private function resolveAlignment(): string
    {
        if (! $this->alignment instanceof Closure) {
            return $this->alignment;
        }

        $resolved = $this->evaluate($this->alignment);
        if (! is_string($resolved)) {
            throw new \UnexpectedValueException('Schema action alignment callbacks must return a string.');
        }

        return $this->validateAlignment($resolved);
    }

    /**
     * @param  list<Action>  $actions
     * @return list<Action>
     */
    private function validateActions(array $actions): array
    {
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new \InvalidArgumentException('Schema actions must extend '.Action::class.'.');
            }
        }

        return array_values($actions);
    }

    private function validateAlignment(string $alignment): string
    {
        if (! in_array($alignment, self::ALIGNMENTS, true)) {
            throw new \InvalidArgumentException("Unsupported schema action alignment [{$alignment}].");
        }

        return $alignment;
    }
}
