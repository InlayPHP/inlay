<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Closure;
use Inlay\Schemas\Component;
use Inlay\Schemas\Concerns\HasExtraActions;
use Inlay\Schemas\Concerns\HasSchema;

final class WizardStep extends Component
{
    use HasExtraActions;
    use HasSchema;

    private string|Closure|null $description = null;

    private string|Closure|null $icon = null;

    private string|Closure|null $completedIcon = null;

    private bool|Closure|null $validateBeforeNext = null;

    private ?Closure $beforeValidationUsing = null;

    private ?Closure $afterValidationUsing = null;

    private ?Closure $haltWhenUsing = null;

    private string|Closure $haltMessage = 'You cannot continue from this step yet.';

    protected function type(): string
    {
        return 'wizard-step';
    }

    public function description(string|Closure|null $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function icon(string|Closure|null $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function completedIcon(string|Closure|null $icon): self
    {
        $this->completedIcon = $icon;

        return $this;
    }

    public function validateBeforeNext(bool|Closure $enabled = true): self
    {
        $this->validateBeforeNext = $enabled;

        return $this;
    }

    public function shouldValidateBeforeNext(bool $wizardDefault): bool
    {
        return $this->validateBeforeNext === null
            ? $wizardDefault
            : $this->resolvePresentationBoolean($this->validateBeforeNext, 'wizard-step validation');
    }

    public function beforeValidation(Closure $callback): self
    {
        $this->beforeValidationUsing = $callback;

        return $this;
    }

    public function afterValidation(Closure $callback): self
    {
        $this->afterValidationUsing = $callback;

        return $this;
    }

    public function haltWhen(Closure $condition, string|Closure $message = 'You cannot continue from this step yet.'): self
    {
        if (is_string($message) && trim($message) === '') {
            throw new \InvalidArgumentException('A wizard halt message cannot be empty.');
        }

        $this->haltWhenUsing = $condition;
        $this->haltMessage = $message;

        return $this;
    }

    /** @param array<string, mixed> $utilities */
    public function runBeforeValidation(array $utilities): void
    {
        $this->runValidationHook($this->beforeValidationUsing, 'before', $utilities);
    }

    /** @param array<string, mixed> $utilities */
    public function runAfterValidation(array $utilities): void
    {
        $this->runValidationHook($this->afterValidationUsing, 'after', $utilities);
    }

    /** @param array<string, mixed> $utilities */
    public function validationHaltMessage(array $utilities): ?string
    {
        if ($this->haltWhenUsing === null) {
            return null;
        }

        $shouldHalt = $this->evaluateValidationClosure($this->haltWhenUsing, $utilities);
        if (! is_bool($shouldHalt)) {
            throw new \UnexpectedValueException('Wizard halt conditions must return a boolean.');
        }
        if (! $shouldHalt) {
            return null;
        }

        $message = $this->haltMessage instanceof Closure
            ? $this->evaluateValidationClosure($this->haltMessage, $utilities)
            : $this->haltMessage;
        if (! is_string($message) || trim($message) === '') {
            throw new \UnexpectedValueException('Wizard halt message callbacks must return a non-empty string.');
        }

        return trim($message);
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            ...$this->serializedSchema(),
            ...$this->serializedExtraActions(),
            'description' => $this->resolvePresentationString($this->description, 'wizard-step description'),
            'icon' => $this->resolvePresentationString($this->icon, 'wizard-step icon'),
            'completedIcon' => $this->resolvePresentationString($this->completedIcon, 'wizard-step completed icon'),
            'validateBeforeNext' => $this->validateBeforeNext === null
                ? null
                : $this->resolvePresentationBoolean($this->validateBeforeNext, 'wizard-step validation'),
        ];
    }

    /** @param array<string, mixed> $utilities */
    private function runValidationHook(?Closure $callback, string $phase, array $utilities): void
    {
        if ($callback === null) {
            return;
        }

        $result = $this->evaluateValidationClosure($callback, [...$utilities, 'phase' => $phase]);
        if ($result !== null) {
            throw new \UnexpectedValueException("Wizard {$phase}-validation hooks must return null.");
        }
    }

    /** @param array<string, mixed> $utilities */
    private function evaluateValidationClosure(Closure $callback, array $utilities): mixed
    {
        $named = [...$utilities, 'component' => $this, 'step' => $this];
        $typed = [self::class => $this, Component::class => $this];
        foreach ($named as $value) {
            if (is_object($value)) {
                $typed[$value::class] = $value;
            }
        }

        return $this->evaluate($callback, $named, $typed, [
            $named['data'] ?? [],
            $this,
            $named['wizard'] ?? null,
        ]);
    }
}
