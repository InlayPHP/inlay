<?php

declare(strict_types=1);

namespace Inlay\Schemas\Concerns;

use Closure;
use Inlay\Schemas\Component;
use Inlay\Schemas\Contracts\ProvidesSchema;
use Inlay\Schemas\Schema;
use Inlay\Schemas\Support\SchemaComposition;

/**
 * Named header and footer slots that hold components rather than actions.
 *
 * Slot components are ordinary schema components: they take part in traversal,
 * keys, state paths, and validation exactly like the container's own schema.
 */
trait HasSchemaSlots
{
    /** @var list<Component>|Closure */
    private array|Closure $headerSchema = [];

    /** @var list<Component>|Closure */
    private array|Closure $footerSchema = [];

    /** @param list<Component|Schema|ProvidesSchema>|Closure $components */
    public function headerSchema(array|Closure $components): static
    {
        $this->headerSchema = $components instanceof Closure
            ? $components
            : SchemaComposition::flatten($components, self::slotMessage('header'));

        return $this;
    }

    /** @param list<Component|Schema|ProvidesSchema>|Closure $components */
    public function footerSchema(array|Closure $components): static
    {
        $this->footerSchema = $components instanceof Closure
            ? $components
            : SchemaComposition::flatten($components, self::slotMessage('footer'));

        return $this;
    }

    /** @return list<Component> */
    public function getHeaderSchema(): array
    {
        return $this->resolveSlot($this->headerSchema, 'header');
    }

    /** @return list<Component> */
    public function getFooterSchema(): array
    {
        return $this->resolveSlot($this->footerSchema, 'footer');
    }

    /**
     * Slot components belong to the component tree, so keys, state paths, and
     * validation reach them like any other child.
     *
     * @return list<Component>
     */
    public function slotComponents(): array
    {
        return [...$this->getHeaderSchema(), ...$this->getFooterSchema()];
    }

    /** @return array{headerSchema: list<Component>, footerSchema: list<Component>} */
    private function serializedSchemaSlots(): array
    {
        return [
            'headerSchema' => $this->getHeaderSchema(),
            'footerSchema' => $this->getFooterSchema(),
        ];
    }

    /**
     * @param  list<Component>|Closure  $components
     * @return list<Component>
     */
    private function resolveSlot(array|Closure $components, string $slot): array
    {
        if (! $components instanceof Closure) {
            return $components;
        }

        $resolved = $this->evaluate($components);
        if (! is_array($resolved) || ($resolved !== [] && ! array_is_list($resolved))) {
            throw new \UnexpectedValueException("Schema {$slot} slot callbacks must return a list of schema components.");
        }

        return SchemaComposition::flatten($resolved, self::slotMessage($slot), \UnexpectedValueException::class);
    }

    private static function slotMessage(string $slot): string
    {
        return "Schema {$slot} slot entries must extend ".Component::class.', embed a '.Schema::class.', or implement '.ProvidesSchema::class.'.';
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
