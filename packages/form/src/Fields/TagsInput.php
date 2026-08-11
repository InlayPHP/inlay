<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Closure;
use Inlay\Forms\Field;

final class TagsInput extends Field
{
    /** @var list<string> */
    private const SPLIT_KEYS = ['Enter', 'Tab', ',', ' ', ';'];

    private string $separator = ',';

    /** @var list<string>|Closure */
    private array|Closure $suggestions = [];

    /** @var list<string> */
    private array $splitKeys = ['Enter'];

    private bool $reorderable = false;

    /** @var list<string> */
    private array $nestedRules = [];

    protected function type(): string
    {
        return 'tags-input';
    }

    public function separator(string $separator): self
    {
        if ($separator === '') {
            throw new \InvalidArgumentException('A tag separator cannot be empty.');
        }

        $this->separator = $separator;

        return $this;
    }

    /** @param list<string>|Closure $suggestions */
    public function suggestions(array|Closure $suggestions): self
    {
        $this->suggestions = $suggestions instanceof Closure
            ? $suggestions
            : $this->assertSuggestions($suggestions);

        return $this;
    }

    /**
     * Keys that commit the typed tag in the browser.
     *
     * @param  list<string>  $keys
     */
    public function splitKeys(array $keys): self
    {
        foreach ($keys as $key) {
            if (! in_array($key, self::SPLIT_KEYS, true)) {
                throw new \InvalidArgumentException("Unsupported tag split key [{$key}].");
            }
        }

        $this->splitKeys = array_values(array_unique($keys));

        return $this;
    }

    public function reorderable(bool $enabled = true): self
    {
        $this->reorderable = $enabled;

        return $this;
    }

    /**
     * Validate every tag, not the list.
     *
     * The Form publishes these under `field.*`, so Laravel reports the failing
     * tag rather than the whole field.
     */
    public function nestedRules(string ...$rules): self
    {
        foreach ($rules as $rule) {
            if (trim($rule) === '') {
                throw new \InvalidArgumentException('A nested tag rule cannot be empty.');
            }
        }

        $this->nestedRules = [...$this->nestedRules, ...array_values($rules)];

        return $this;
    }

    /** @return list<string> */
    public function nestedValidationRules(): array
    {
        return $this->nestedRules;
    }

    /**
     * Normalize whatever the browser sent into a clean list of tags.
     *
     * @param  array<string, mixed>  $data
     */
    public function mutateStateForValidation(mixed $state, array $data): mixed
    {
        return parent::mutateStateForValidation($this->normalize($state), $data);
    }

    /** @param array<string, mixed> $data */
    public function dehydrateState(mixed $state, array $data): mixed
    {
        return parent::dehydrateState($this->normalize($state), $data);
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'separator' => $this->separator,
            'suggestions' => $this->resolvedSuggestions(),
            'splitKeys' => $this->splitKeys,
            'reorderable' => $this->reorderable,
        ];
    }

    /** @return list<string> */
    private function resolvedSuggestions(): array
    {
        if (! $this->suggestions instanceof Closure) {
            return $this->suggestions;
        }

        $resolved = $this->evaluate($this->suggestions);
        if (! is_array($resolved)) {
            throw new \UnexpectedValueException('Tag suggestion callbacks must return a list of strings.');
        }

        return $this->assertSuggestions($resolved);
    }

    /**
     * @param  array<int|string, mixed>  $suggestions
     * @return list<string>
     */
    private function assertSuggestions(array $suggestions): array
    {
        foreach ($suggestions as $suggestion) {
            if (! is_string($suggestion)) {
                throw new \InvalidArgumentException('Tag suggestions must be strings.');
            }
        }

        return array_values(array_unique(array_map('strval', $suggestions)));
    }

    /**
     * A string payload is split on the separator, and every tag is trimmed and
     * deduplicated, so the model never stores blank or repeated tags.
     */
    private function normalize(mixed $state): mixed
    {
        if ($state === null || $state === '') {
            return $state;
        }
        if (is_string($state)) {
            $state = explode($this->separator, $state);
        }
        if (! is_array($state) || ! array_is_list($state)) {
            throw new \InvalidArgumentException("Tags field [{$this->name()}] state must be a list of tags.");
        }

        $tags = [];
        foreach ($state as $tag) {
            if (! is_string($tag) && ! is_int($tag) && ! is_float($tag)) {
                throw new \InvalidArgumentException("Tags field [{$this->name()}] tags must be scalar.");
            }
            $tag = trim((string) $tag);
            if ($tag !== '' && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
