<?php

declare(strict_types=1);

namespace Inlay\Schemas\Concerns;

use Closure;
use Inlay\Actions\Action;
use Inlay\Schemas\Support\ActionAlignment;

trait HasExtraActions
{
    /** @var list<Action>|Closure */
    private array|Closure $headerActions = [];

    /** @var list<Action>|Closure */
    private array|Closure $footerActions = [];

    private string $headerActionsAlignment = ActionAlignment::HEADER_DEFAULT;

    private string $footerActionsAlignment = ActionAlignment::FOOTER_DEFAULT;

    /** @param list<Action>|Closure $actions */
    public function headerActions(array|Closure $actions): static
    {
        $this->headerActions = $actions instanceof Closure
            ? $actions
            : $this->validateExtraActions($actions, 'header');

        return $this;
    }

    /** @param list<Action>|Closure $actions */
    public function footerActions(array|Closure $actions): static
    {
        $this->footerActions = $actions instanceof Closure
            ? $actions
            : $this->validateExtraActions($actions, 'footer');

        return $this;
    }

    /**
     * Where the header action row sits within its container.
     *
     * Nothing serialized this, so each renderer picked a default and they disagreed.
     * PHP decides it now, and both renderers read the same key.
     */
    public function headerActionsAlignment(string $alignment): static
    {
        ActionAlignment::assert($alignment, 'schema header actions alignment');
        $this->headerActionsAlignment = $alignment;

        return $this;
    }

    /** Where the footer action row sits within its container. */
    public function footerActionsAlignment(string $alignment): static
    {
        ActionAlignment::assert($alignment, 'schema footer actions alignment');
        $this->footerActionsAlignment = $alignment;

        return $this;
    }

    /** @return array{headerActions: list<Action>, footerActions: list<Action>, headerActionsAlignment: string, footerActionsAlignment: string} */
    private function serializedExtraActions(): array
    {
        return [
            'headerActions' => $this->resolveExtraActions($this->headerActions, 'header'),
            'footerActions' => $this->resolveExtraActions($this->footerActions, 'footer'),
            'headerActionsAlignment' => $this->headerActionsAlignment,
            'footerActionsAlignment' => $this->footerActionsAlignment,
        ];
    }

    /**
     * Resolve an action slot that may be closure-backed, validating the result
     * exactly as an eager list would have been.
     *
     * @param  list<Action>|Closure  $actions
     * @return list<Action>
     */
    private function resolveExtraActions(array|Closure $actions, string $slot): array
    {
        if (! $actions instanceof Closure) {
            return $actions;
        }

        $resolved = $this->evaluate($actions);
        if (! is_array($resolved) || ($resolved !== [] && ! array_is_list($resolved))) {
            throw new \UnexpectedValueException("Schema {$slot} action callbacks must return a list of actions.");
        }

        return $this->validateExtraActions($resolved, $slot);
    }

    /**
     * @param  list<Action>  $actions
     * @return list<Action>
     */
    private function validateExtraActions(array $actions, string $slot): array
    {
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new \InvalidArgumentException("Schema {$slot} actions must extend ".Action::class.'.');
            }
        }

        return array_values($actions);
    }

    /**
     * @param  array<string, mixed>  $named
     * @param  array<class-string, object>  $typed
     * @param  list<mixed>  $positional
     */
    abstract protected function evaluate(
        mixed $value,
        array $named = [],
        array $typed = [],
        array $positional = [],
    ): mixed;
}
