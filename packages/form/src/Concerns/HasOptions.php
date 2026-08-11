<?php

declare(strict_types=1);

namespace Inlay\Forms\Concerns;

use Closure;
use Inlay\Forms\Support\Get;
use Inlay\Schemas\SchemaContext;

trait HasOptions
{
    /** @var array<string|int, string>|Closure */
    protected array|Closure $options = [];

    /**
     * Define the available options, optionally from the current form state.
     *
     * The callback is evaluated while the schema is serialized, so it can use
     * the normal Schema utilities (`Get`, `$record`, `$operation`, and so on)
     * without exposing a closure to the browser. This mirrors the API's
     * `options(fn (...) => [...])` API and also means a live schema patch gets
     * a fresh option list.
     *
     * @param  array<string|int, string>|Closure  $options
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    /** @return array<string|int, string> */
    protected function resolvedOptions(): array
    {
        if ($this->options instanceof Closure) {
            $context = $this->schemaContext ?? SchemaContext::make();
            $get = new Get($context->get(...));
            $options = $this->evaluate($this->options, [
                'component' => $this,
                'context' => $context,
                'field' => $this,
                'get' => $context->get(...),
                'operation' => $context->operation,
                'record' => $context->record,
                'state' => $context->get($this->name()),
            ], [
                Get::class => $get,
                SchemaContext::class => $context,
            ], [$get, $this]);
        } else {
            $options = $this->options;
        }

        if (! is_array($options)) {
            throw new \UnexpectedValueException("Options callback for [{$this->name}] must return an array of value => label pairs.");
        }

        foreach ($options as $value => $label) {
            if ((! is_string($value) && ! is_int($value)) || ! is_string($label)) {
                throw new \UnexpectedValueException("Options for [{$this->name}] must contain only string or integer values and string labels.");
            }
        }

        return $options;
    }

    /** @return list<array{value: string|int, label: string}> */
    protected function serializedOptions(): array
    {
        $normalized = [];

        foreach ($this->resolvedOptions() as $value => $label) {
            $normalized[] = ['value' => $value, 'label' => $label];
        }

        return $normalized;
    }
}
