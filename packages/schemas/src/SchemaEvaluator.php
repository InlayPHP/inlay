<?php

declare(strict_types=1);

namespace Inlay\Schemas;

use Closure;
use Illuminate\Contracts\Container\Container;
use Inlay\Support\ClosureEvaluator;
use ReflectionParameter;
use Throwable;

/**
 * Evaluates schema callbacks with one stable set of fluent utilities.
 *
 * Community components should use this evaluator through Component::evaluate()
 * or Schema::evaluate() instead of reflecting over callbacks themselves.
 */
final readonly class SchemaEvaluator
{
    private function __construct(
        private SchemaContext $context,
        private ?Schema $schema = null,
        private ?Component $component = null,
        private ?Container $container = null,
    ) {
    }

    public static function make(
        SchemaContext $context,
        ?Schema $schema = null,
        ?Component $component = null,
        ?Container $container = null,
    ): self
    {
        return new self($context, $schema, $component, $container);
    }

    /**
     * @param  array<string, mixed>  $named
     * @param  array<class-string, object>  $typed
     * @param  list<mixed>  $positional
     */
    public function evaluate(Closure $callback, array $named = [], array $typed = [], array $positional = []): mixed
    {
        $record = $this->context->record;
        $component = $this->component;
        $statePath = $component?->getStatePath() ?? '';

        // A component bound to a nested state key reads relative to that key,
        // so `$get('plan')` inside it means `billing.plan` without the caller
        // repeating the container path. Unbound components stay root-relative.
        $get = $statePath === ''
            ? $this->context->get(...)
            : fn (string $path, mixed $default = null): mixed => $this->context->get(
                $component->resolveStatePath($path),
                $default,
            );

        $defaultNamed = array_filter([
            'component' => $this->component,
            'context' => $this->context,
            'data' => $this->context->state,
            'get' => $get,
            'model' => $record,
            'operation' => $this->context->operation,
            'record' => $record,
            'schema' => $this->schema,
        ], static fn (mixed $value): bool => $value !== null);

        $defaultNamed['state'] = $statePath === '' ? $this->context->state : $this->context->get($statePath);

        $defaultTyped = [SchemaContext::class => $this->context];
        foreach ([$this->schema, $this->component, is_object($record) ? $record : null] as $value) {
            if (is_object($value)) {
                $defaultTyped[$value::class] = $value;
            }
        }
        if ($this->component !== null) {
            $defaultTyped[Component::class] = $this->component;
        }
        if ($this->schema !== null) {
            $defaultTyped[Schema::class] = $this->schema;
        }

        return ClosureEvaluator::evaluate(
            $callback,
            [...$defaultNamed, ...$named],
            [...$defaultTyped, ...$typed],
            $positional,
            $this->resolveFromContainer(...),
        );
    }

    private function resolveFromContainer(string $type, ReflectionParameter $parameter): mixed
    {
        if ($this->container === null) {
            return null;
        }

        if (! $this->container->bound($type) && (! class_exists($type) || enum_exists($type))) {
            return null;
        }

        try {
            return $this->container->make($type);
        } catch (Throwable $exception) {
            throw new \InvalidArgumentException(
                "Unable to resolve schema callback parameter [\${$parameter->getName()}] of type [{$type}] from the Laravel container.",
                previous: $exception,
            );
        }
    }
}
