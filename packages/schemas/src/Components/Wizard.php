<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Closure;
use Inlay\Actions\Action;
use Inlay\Schemas\Component;
use Inlay\Schemas\Concerns\HasExtraActions;
use Inlay\Schemas\Concerns\HasSchema;

final class Wizard extends Component
{
    use HasExtraActions;
    use HasSchema;

    private bool $skippable = false;

    private int $startOnStep = 1;

    private ?string $queryStringKey = null;

    private ?Action $nextAction = null;

    private ?Action $previousAction = null;

    private ?Action $submitAction = null;

    private bool $validateSteps = false;

    private ?string $validationEndpoint = null;

    private string $validationMethod = 'post';

    protected function type(): string
    {
        return 'wizard';
    }

    /** @param list<Component>|Closure $steps */
    public function steps(array|Closure $steps): self
    {
        return $this->schema($steps);
    }

    public function skippable(bool $enabled = true): self
    {
        $this->skippable = $enabled;

        return $this;
    }

    public function startOnStep(int $step): self
    {
        if ($step < 1) {
            throw new \InvalidArgumentException('Wizard start step must be at least 1.');
        }

        $this->startOnStep = $step;

        return $this;
    }

    public function persistStepInQueryString(string $key = 'step'): self
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $key)) {
            throw new \InvalidArgumentException('Wizard query-string key is invalid.');
        }

        $this->queryStringKey = $key;

        return $this;
    }

    public function nextAction(Action|Closure $action): self
    {
        $this->nextAction = $this->resolveNavigationAction($action, 'next');

        return $this;
    }

    public function previousAction(Action|Closure $action): self
    {
        $this->previousAction = $this->resolveNavigationAction($action, 'previous');

        return $this;
    }

    public function submitAction(Action|Closure $action): self
    {
        $this->submitAction = $this->resolveNavigationAction($action, 'submit');

        return $this;
    }

    public function validateSteps(bool $enabled = true): self
    {
        $this->validateSteps = $enabled;

        return $this;
    }

    public function validatesSteps(): bool
    {
        return $this->validateSteps;
    }

    public function configureValidationEndpoint(?string $endpoint, string $method): void
    {
        $method = strtolower($method);
        if (! in_array($method, ['post', 'put', 'patch', 'delete'], true)) {
            throw new \InvalidArgumentException("Unsupported wizard validation method [{$method}].");
        }

        $this->validationEndpoint = $endpoint;
        $this->validationMethod = $method;
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            ...$this->serializedExtraActions(),
            'steps' => $this->getSchema(),
            'skippable' => $this->skippable,
            'startOnStep' => $this->startOnStep,
            'queryStringKey' => $this->queryStringKey,
            'nextAction' => $this->nextAction,
            'previousAction' => $this->previousAction,
            'submitAction' => $this->submitAction,
            'validateSteps' => $this->validateSteps,
            'validationEndpoint' => $this->validationEndpoint,
            'validationMethod' => $this->validationMethod,
        ];
    }

    private function resolveNavigationAction(Action|Closure $configuration, string $name): Action
    {
        $action = Action::make($name);
        $resolved = $configuration instanceof Closure
            ? $this->evaluate($configuration, [
                'action' => $action,
                'component' => $this,
                'wizard' => $this,
            ], [Action::class => $action, self::class => $this], [$action, $this])
            : $configuration;
        $resolved ??= $action;
        if (! $resolved instanceof Action) {
            throw new \UnexpectedValueException('Wizard navigation action callbacks must return an action.');
        }
        $payload = $resolved->jsonSerialize();
        if ($payload['url'] !== null || $payload['requiresConfirmation'] || $payload['method'] !== 'get') {
            throw new \InvalidArgumentException('Wizard navigation actions are local controls and cannot define URLs, mutations, or confirmation modals.');
        }

        return $resolved;
    }
}
