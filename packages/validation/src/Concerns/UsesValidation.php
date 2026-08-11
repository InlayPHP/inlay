<?php

declare(strict_types=1);

namespace Inlay\Validation\Concerns;

use Illuminate\Container\Container;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\Validation;
use InvalidArgumentException;
use RuntimeException;

trait UsesValidation
{
    private ?Validation $inlayResolvedValidation = null;

    /** @return class-string<Validation> */
    abstract protected function validation(): string;

    protected function validationOperation(): string
    {
        return 'default';
    }

    protected function validationSource(): string
    {
        return ValidationContext::SOURCE_FORM;
    }

    protected function validationRecord(): mixed
    {
        return null;
    }

    protected function validationUser(): mixed
    {
        return method_exists($this, 'user') ? $this->user() : null;
    }

    /** @return array<string, mixed> */
    protected function validationOptions(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->resolvedValidation()->rules($this->validationContext());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->resolvedValidation()->messages($this->validationContext());
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->resolvedValidation()->attributes($this->validationContext());
    }

    /** @return list<callable> */
    public function after(): array
    {
        return $this->resolvedValidation()->after($this->validationContext());
    }

    protected function prepareForValidation(): void
    {
        $data = $this->requestData();
        $context = $this->validationContext($data);
        $prepared = $this->resolvedValidation()->prepare($data, $context);

        if (! method_exists($this, 'replace')) {
            throw new RuntimeException('A request using UsesValidation must provide replace().');
        }

        $this->replace($prepared);
    }

    /** @param array<string, mixed>|null $data */
    private function validationContext(?array $data = null): ValidationContext
    {
        return ValidationContext::make(
            operation: $this->validationOperation(),
            source: $this->validationSource(),
            record: $this->validationRecord(),
            user: $this->validationUser(),
            options: $this->validationOptions(),
        )->withData($data ?? $this->requestData());
    }

    private function resolvedValidation(): Validation
    {
        if ($this->inlayResolvedValidation instanceof Validation) {
            return $this->inlayResolvedValidation;
        }

        $validation = $this->validation();
        if (! is_subclass_of($validation, Validation::class)) {
            throw new InvalidArgumentException("Validation class [{$validation}] must extend ".Validation::class.'.');
        }

        $container = Container::getInstance();
        $resolved = $container?->make($validation) ?? new $validation;

        if (! $resolved instanceof Validation) {
            throw new RuntimeException("Validation class [{$validation}] must extend ".Validation::class.'.');
        }

        return $this->inlayResolvedValidation = $resolved;
    }

    /** @return array<string, mixed> */
    private function requestData(): array
    {
        if (! method_exists($this, 'all')) {
            throw new RuntimeException('A request using UsesValidation must provide all().');
        }

        return $this->all();
    }
}
