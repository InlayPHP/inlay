<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Closure;
use Inlay\Forms\Field;
use Inlay\Forms\Support\Get;
use Inlay\Schemas\SchemaContext;

/**
 * A computed, read-only field. Its content is resolved in PHP from the current
 * form state, so a total, a preview, or a derived label stays authoritative and
 * never becomes part of the submitted payload.
 */
final class Placeholder extends Field
{
    private string|Closure|null $content = null;

    protected function type(): string
    {
        return 'placeholder';
    }

    /**
     * The displayed content. A closure receives the usual field utilities,
     * including `$get` for reading sibling state.
     */
    public function content(string|Closure|null $content): self
    {
        $this->content = $content;

        return $this;
    }

    /** A placeholder is computed, so it never contributes to submitted data. */
    public function isConfiguredToDehydrate(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $content = $this->content;

        if ($content instanceof Closure) {
            $context = $this->schemaContext ?? SchemaContext::make();
            $get = new Get($context->get(...));
            $content = $this->evaluate(
                $content,
                [
                    'component' => $this,
                    'context' => $context,
                    'field' => $this,
                    'get' => $get,
                    'operation' => $context->operation,
                    'record' => $context->record,
                    'state' => $context->get($this->name()),
                ],
                [self::class => $this, Field::class => $this, Get::class => $get, SchemaContext::class => $context],
                [$get, $this],
            );
        }

        if ($content !== null && ! is_string($content)) {
            throw new \UnexpectedValueException("Placeholder [{$this->name}] content must resolve to a string or null.");
        }

        return [
            ...parent::jsonSerialize(),
            'content' => $content,
            'dehydrated' => false,
        ];
    }
}
