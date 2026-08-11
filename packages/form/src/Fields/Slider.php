<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Inlay\Forms\Field;

final class Slider extends Field
{
    private float $min = 0;

    private float $max = 100;

    private float $step = 1;

    private bool $range = false;

    private bool $showValue = true;

    protected function type(): string
    {
        return 'slider';
    }

    public function minValue(int|float $value, bool $condition = true): static
    {
        if (! $condition) {
            return $this;
        }
        $this->min = (float) $value;

        return $this->range ? $this : parent::minValue($value);
    }

    public function maxValue(int|float $value, bool $condition = true): static
    {
        if (! $condition) {
            return $this;
        }
        $this->max = (float) $value;

        return $this->range ? $this : parent::maxValue($value);
    }

    public function step(int|float $value): self
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException('A slider step must be greater than zero.');
        }

        $this->step = (float) $value;

        return $this->range ? $this : $this->multipleOf($value);
    }

    /**
     * Select a low and high value instead of a single one.
     *
     * A range exchanges a two-element list, so the scalar Laravel rules are
     * dropped and the pair is checked here instead.
     */
    public function range(bool $enabled = true): self
    {
        $this->range = $enabled;

        return $enabled ? $this->dropScalarRules() : $this;
    }

    public function showValue(bool $enabled = true): self
    {
        $this->showValue = $enabled;

        return $this;
    }

    public function isRange(): bool
    {
        return $this->range;
    }

    /** @param array<string, mixed> $data */
    public function mutateStateForValidation(mixed $state, array $data): mixed
    {
        return parent::mutateStateForValidation($this->assertWithinRange($state), $data);
    }

    /** @param array<string, mixed> $data */
    public function dehydrateState(mixed $state, array $data): mixed
    {
        return parent::dehydrateState($this->assertWithinRange($state), $data);
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'range' => $this->range,
            'showValue' => $this->showValue,
        ];
    }

    private function dropScalarRules(): self
    {
        $dropped = ['numeric', 'min:', 'max:', 'multiple_of:'];
        $this->rules = array_values(array_filter(
            $this->rules,
            static function (mixed $rule) use ($dropped): bool {
                if (! is_string($rule)) {
                    return true;
                }
                foreach ($dropped as $prefix) {
                    if ($rule === $prefix || str_starts_with($rule, $prefix)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        return $this;
    }

    /**
     * A browser control cannot be trusted to respect its own bounds, so the
     * submitted value is checked against them here.
     */
    private function assertWithinRange(mixed $state): mixed
    {
        if ($state === null || $state === '') {
            return $state;
        }

        if (! $this->range) {
            return $this->assertValue($state, $this->name());
        }

        if (! is_array($state) || ! array_is_list($state) || count($state) !== 2) {
            throw new \InvalidArgumentException("Slider field [{$this->name()}] range state must be a list of two values.");
        }

        $low = $this->assertValue($state[0], $this->name().' minimum');
        $high = $this->assertValue($state[1], $this->name().' maximum');
        if ($low > $high) {
            throw new \InvalidArgumentException("Slider field [{$this->name()}] minimum cannot exceed its maximum.");
        }

        return [$low, $high];
    }

    private function assertValue(mixed $value, string $label): float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw new \InvalidArgumentException("Slider field [{$label}] must be numeric.");
        }

        $value = (float) $value;
        if ($value < $this->min || $value > $this->max) {
            throw new \InvalidArgumentException("Slider field [{$label}] must be between {$this->min} and {$this->max}.");
        }

        // Floating point steps cannot be compared exactly, so the remainder is
        // measured against a tolerance derived from the step itself.
        $offset = fmod($value - $this->min, $this->step);
        if (abs($offset) > $this->step * 1e-9 && abs($offset - $this->step) > $this->step * 1e-9) {
            throw new \InvalidArgumentException("Slider field [{$label}] must move in steps of {$this->step}.");
        }

        return $value;
    }
}
