<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Inlay\Forms\Field;

final class TextInput extends Field
{
    private const DEFAULT_TEL_REGEX = '/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/';

    private string $inputType = 'text';

    private bool $revealable = false;

    private bool $copyable = false;

    private ?string $copyMessage = null;

    private ?int $copyMessageDuration = null;

    private ?int $maxLength = null;

    private ?string $mask = null;

    /** @var list<string> */
    private array $stripCharacters = [];

    private bool $trim = false;

    /** @var list<string> */
    private array $datalist = [];

    private ?string $autocomplete = null;

    private ?string $autocapitalize = null;

    private ?string $inputMode = null;

    private ?string $telRegex = null;

    private int|float|null $min = null;

    private int|float|null $max = null;

    private int|float|string|null $step = null;

    protected function type(): string
    {
        return 'text';
    }

    public function email(bool $condition = true): static
    {
        if (! $condition) {
            return $this;
        }
        $this->inputType = 'email';

        return parent::email();
    }

    public function password(): self
    {
        $this->inputType = 'password';

        return $this;
    }

    /**
     * Add an accessible Show/Hide control to a password input.
     *
     * The flag is only serialized for password inputs. This keeps a stale
     * revealable configuration harmless if an application changes the input
     * type later in a fluent chain.
     */
    public function revealable(bool $condition = true): self
    {
        $this->revealable = $condition;

        return $this;
    }

    /** Add an accessible copy control for the current input value. */
    public function copyable(bool $condition = true): self
    {
        $this->copyable = $condition;

        return $this;
    }

    public function copyMessage(?string $message): self
    {
        if ($message !== null && trim($message) === '') {
            throw new \InvalidArgumentException('Text input copy message cannot be empty.');
        }

        $this->copyMessage = $message;

        return $this;
    }

    public function copyMessageDuration(int $duration): self
    {
        if ($duration < 0) {
            throw new \InvalidArgumentException('Text input copy message duration must be zero or greater.');
        }

        $this->copyMessageDuration = $duration;

        return $this;
    }

    public function numeric(bool $condition = true): static
    {
        if (! $condition) {
            return $this;
        }
        $this->inputType = 'number';

        return parent::numeric();
    }

    /** Publish the browser constraint and keep the server rule authoritative. */
    public function minValue(int|float $value, bool $condition = true): static
    {
        if (! $condition) {
            return $this;
        }
        $this->numeric();
        $this->min = $value;

        return parent::minValue($value);
    }

    /** Publish the browser constraint and keep the server rule authoritative. */
    public function maxValue(int|float $value, bool $condition = true): static
    {
        if (! $condition) {
            return $this;
        }
        $this->numeric();
        $this->max = $value;

        return parent::maxValue($value);
    }

    /**
     * Constrain the increments a numeric input accepts.
     *
     * `'any'` lets the browser accept any value; a number also validates on the
     * server, because a browser constraint is a hint, not a guarantee.
     */
    public function step(int|float|string $step): self
    {
        if (is_string($step)) {
            if ($step !== 'any') {
                throw new \InvalidArgumentException("Unsupported numeric step [{$step}].");
            }
            $this->numeric();
            $this->step = $step;

            return $this;
        }

        $this->numeric();
        $this->step = $step;

        return $this->multipleOf($step);
    }

    public function integer(bool $condition = true): static
    {
        if (! $condition) {
            return $this;
        }
        $this->numeric();
        $this->step ??= 1;
        $this->inputMode ??= 'numeric';

        return parent::integer();
    }

    public function tel(bool $condition = true): self
    {
        if ($condition) {
            $this->inputType = 'tel';
        }

        return $this;
    }

    /**
     * Set the regular expression used by a telephone input.
     *
     * The rule is materialized only while the input is configured as `tel`,
     * so changing a feature flag back to a normal text input does not leave a
     * stale phone constraint behind.
     */
    public function telRegex(string $regex): self
    {
        if (! $this->isValidRegex($regex)) {
            throw new \InvalidArgumentException('Telephone validation requires a valid PHP regular expression.');
        }

        $this->telRegex = $regex;

        return $this;
    }

    public function url(bool $condition = true): static
    {
        if (! $condition) {
            return $this;
        }
        $this->inputType = 'url';

        return parent::url();
    }

    public function maxLength(int $length, bool $condition = true): static
    {
        if (! $condition) {
            return $this;
        }
        if ($length < 1) {
            throw new \InvalidArgumentException('Maximum length must be at least 1.');
        }

        $this->maxLength = $length;

        return parent::maxLength($length);
    }

    /**
     * Mask tokens: 9 = digit, A = letter, * = letter or digit. Prefix a token with a backslash for a literal.
     */
    public function mask(string $pattern): self
    {
        if ($pattern === '' || mb_strlen($pattern) > 200 || preg_match('/[\x00-\x1F\x7F]/u', $pattern) === 1) {
            throw new \InvalidArgumentException('A text mask must contain 1–200 printable characters.');
        }
        if (preg_match('/(?<!\\\\)[9A*]/u', $pattern) !== 1) {
            throw new \InvalidArgumentException('A text mask must contain at least one unescaped 9, A, or * token.');
        }

        $this->mask = $pattern;

        return $this;
    }

    /** @param string|list<string> $characters */
    public function stripCharacters(string|array $characters): self
    {
        $characters = is_string($characters) ? mb_str_split($characters) : $characters;
        foreach ($characters as $character) {
            if (! is_string($character) || $character === '') {
                throw new \InvalidArgumentException('Characters stripped from a text input must be non-empty strings.');
            }
        }
        $this->stripCharacters = array_values(array_unique($characters));

        return $this;
    }

    /** Trim leading and trailing whitespace before validation and dehydration. */
    public function trim(bool $condition = true): self
    {
        $this->trim = $condition;

        return $this;
    }

    /** @param list<string> $options */
    public function datalist(array $options): self
    {
        foreach ($options as $option) {
            if (! is_string($option) || trim($option) === '') {
                throw new \InvalidArgumentException('Text input datalist options must be non-empty strings.');
            }
        }
        $this->datalist = array_values(array_unique($options));

        return $this;
    }

    public function autocomplete(bool|string $value = true): self
    {
        $value = is_bool($value) ? ($value ? 'on' : 'off') : trim($value);
        if ($value === '' || preg_match('/^[a-z][a-z0-9-]*(?: [a-z][a-z0-9-]*)*$/', $value) !== 1) {
            throw new \InvalidArgumentException('Text input autocomplete must contain valid HTML autocomplete tokens.');
        }
        $this->autocomplete = $value;

        return $this;
    }

    /** Configure browser autocapitalization for mobile text keyboards. */
    public function autocapitalize(string $value): self
    {
        $value = strtolower(trim($value));
        if (! in_array($value, ['none', 'sentences', 'words', 'characters', 'on', 'off'], true)) {
            throw new \InvalidArgumentException("Unsupported text input autocapitalize value [{$value}].");
        }

        $this->autocapitalize = $value;

        return $this;
    }

    public function inputMode(string $mode): self
    {
        if (! in_array($mode, ['none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'], true)) {
            throw new \InvalidArgumentException("Unsupported text input mode [{$mode}].");
        }
        $this->inputMode = $mode;

        return $this;
    }

    public function mutateStateForValidation(mixed $state, array $data): mixed
    {
        return $this->normalize(parent::mutateStateForValidation($state, $data));
    }

    public function dehydrateState(mixed $state, array $data): mixed
    {
        return $this->normalize(parent::dehydrateState($state, $data));
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'inputType' => $this->inputType,
            'revealable' => $this->revealable && $this->inputType === 'password',
            'copyable' => $this->copyable,
            'copyMessage' => $this->copyMessage,
            'copyMessageDuration' => $this->copyMessageDuration,
            'maxLength' => $this->maxLength,
            'mask' => $this->mask,
            'stripCharacters' => $this->stripCharacters,
            'trim' => $this->trim,
            'datalist' => $this->datalist,
            'autocomplete' => $this->autocomplete,
            'autocapitalize' => $this->autocapitalize,
            'inputMode' => $this->inputMode,
            'telRegex' => $this->inputType === 'tel' ? ($this->telRegex ?? self::DEFAULT_TEL_REGEX) : null,
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
        ];
    }

    /** @return list<string> */
    public function validationRules(): array
    {
        $rules = parent::validationRules();

        if ($this->inputType === 'tel') {
            $rule = 'regex:'.($this->telRegex ?? self::DEFAULT_TEL_REGEX);

            if (! in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    private function normalize(mixed $state): mixed
    {
        if (! is_string($state)) {
            return $state;
        }

        if ($this->trim) {
            $state = trim($state);
        }

        return $this->stripCharacters !== [] ? str_replace($this->stripCharacters, '', $state) : $state;
    }

    private function isValidRegex(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
