<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Inlay\Forms\Field;

final class KeyValue extends Field
{
    private string $keyLabel = 'Key';

    private string $valueLabel = 'Value';

    private ?string $keyPlaceholder = null;

    private ?string $valuePlaceholder = null;

    private string $addActionLabel = 'Add row';

    private bool $addable = true;

    private bool $deletable = true;

    private bool $editableKeys = true;

    private bool $editableValues = true;

    private bool $reorderable = false;

    protected function type(): string
    {
        return 'key-value';
    }

    public function keyLabel(string $label): self
    {
        $this->keyLabel = $label;

        return $this;
    }

    public function valueLabel(string $label): self
    {
        $this->valueLabel = $label;

        return $this;
    }

    public function keyPlaceholder(?string $placeholder): self
    {
        $this->keyPlaceholder = $placeholder;

        return $this;
    }

    public function valuePlaceholder(?string $placeholder): self
    {
        $this->valuePlaceholder = $placeholder;

        return $this;
    }

    public function addActionLabel(string $label): self
    {
        $this->addActionLabel = $label;

        return $this;
    }

    public function addable(bool $enabled = true): self
    {
        $this->addable = $enabled;

        return $this;
    }

    public function deletable(bool $enabled = true): self
    {
        $this->deletable = $enabled;

        return $this;
    }

    public function editableKeys(bool $enabled = true): self
    {
        $this->editableKeys = $enabled;

        return $this;
    }

    public function editableValues(bool $enabled = true): self
    {
        $this->editableValues = $enabled;

        return $this;
    }

    public function reorderable(bool $enabled = true): self
    {
        $this->reorderable = $enabled;

        return $this;
    }

    /**
     * Reject a payload that is not a flat map of scalar values.
     *
     * The browser controls are a convenience: a forged submission has to fail
     * here rather than reach the model as nested arrays or objects.
     *
     * @param  array<string, mixed>  $data
     */
    public function mutateStateForValidation(mixed $state, array $data): mixed
    {
        return parent::mutateStateForValidation($this->assertFlatMap($state), $data);
    }

    /** @param array<string, mixed> $data */
    public function dehydrateState(mixed $state, array $data): mixed
    {
        return parent::dehydrateState($this->assertFlatMap($state), $data);
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'keyLabel' => $this->keyLabel,
            'valueLabel' => $this->valueLabel,
            'keyPlaceholder' => $this->keyPlaceholder,
            'valuePlaceholder' => $this->valuePlaceholder,
            'addActionLabel' => $this->addActionLabel,
            'addable' => $this->addable,
            'deletable' => $this->deletable,
            'editableKeys' => $this->editableKeys,
            'editableValues' => $this->editableValues,
            'reorderable' => $this->reorderable,
        ];
    }

    private function assertFlatMap(mixed $state): mixed
    {
        if ($state === null || $state === []) {
            return $state;
        }
        if (! is_array($state) || array_is_list($state)) {
            throw new \InvalidArgumentException("Key-value field [{$this->name()}] state must be a map of keys to values.");
        }

        foreach ($state as $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new \InvalidArgumentException("Key-value field [{$this->name()}] values must be scalar.");
            }
        }

        return $state;
    }
}
