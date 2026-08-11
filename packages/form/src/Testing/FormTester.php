<?php

declare(strict_types=1);

namespace Inlay\Forms\Testing;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inlay\Forms\Field;
use Inlay\Forms\Form;
use Inlay\Support\Testing\Assertions;
use Inlay\Validation\ValidationRunner;

final class FormTester
{
    /** @var array<string, mixed> */
    private array $state;

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, array<string, mixed>> */
    private array $failedRules = [];

    /** @var array<string, mixed>|null */
    private ?array $validated = null;

    private function __construct(private readonly Form $form)
    {
        $this->state = (array) $form->jsonSerialize()['data'];
    }

    public static function make(Form $form): self
    {
        return new self($form);
    }

    public function form(): Form
    {
        return $this->form;
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return $this->state;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @param array<string, mixed> $state */
    public function fillForm(array $state): self
    {
        $this->state = array_replace_recursive($this->state, $state);
        $this->form->data($this->state);
        $this->clearValidation();

        return $this;
    }

    public function assertFormFieldExists(string $name, ?Closure $check = null): self
    {
        $field = $this->form->getField($name);
        Assertions::true($field instanceof Field, "Expected form field [{$name}] to exist.");
        if ($check !== null) {
            Assertions::true(
                $check($field) === true,
                "Form field [{$name}] exists, but its configuration assertion failed.",
            );
        }

        return $this;
    }

    public function assertFormFieldDoesNotExist(string $name): self
    {
        Assertions::true(
            $this->form->getField($name) === null,
            "Expected form field [{$name}] not to exist.",
        );

        return $this;
    }

    /** @param array<string, mixed>|Closure(array<string, mixed>): array<string, mixed> $expected */
    public function assertSchemaStateSet(array|Closure $expected): self
    {
        $expected = $expected instanceof Closure ? $expected($this->state) : $expected;
        foreach (Arr::dot($expected) as $path => $value) {
            Assertions::same(
                $value,
                Arr::get($this->state, $path),
                "Form state [{$path}] does not match the expected value.",
            );
        }

        return $this;
    }

    public function validate(
        ValidationFactory $factory,
        ?ValidationRunner $runner = null,
        mixed $record = null,
        mixed $user = null,
        ?Request $request = null,
    ): self {
        $this->clearValidation();
        try {
            $this->validated = $this->form->hasValidation()
                ? $this->form->validate(
                    $runner ?? throw new \LogicException('Centralized form validation tests require a ValidationRunner.'),
                    $this->state,
                    $record,
                    $user,
                    options: ['request' => $request],
                )
                : $this->form->validateWithFactory($factory, $this->state, $request);
            $this->state = [...$this->state, ...$this->validated];
        } catch (ValidationException $exception) {
            $this->errors = $exception->errors();
            $this->failedRules = $exception->validator->failed();
        }

        return $this;
    }

    /** @param list<string>|array<string, string> $expected */
    public function assertHasFormErrors(array $expected): self
    {
        foreach ($expected as $field => $rule) {
            if (is_int($field)) {
                Assertions::true(isset($this->errors[$rule]), "Expected form field [{$rule}] to have a validation error.");
                continue;
            }
            Assertions::true(isset($this->errors[$field]), "Expected form field [{$field}] to have a validation error.");
            Assertions::true(
                isset($this->failedRules[$field][self::normalizeRule($rule)]),
                "Expected form field [{$field}] to fail the [{$rule}] rule.",
            );
        }

        return $this;
    }

    public function assertHasNoFormErrors(): self
    {
        Assertions::same([], $this->errors, 'Expected the form to have no validation errors.');

        return $this;
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        if ($this->validated === null) {
            Assertions::fail('The form has not completed validation successfully.');
        }

        return $this->validated;
    }

    private function clearValidation(): void
    {
        $this->errors = [];
        $this->failedRules = [];
        $this->validated = null;
    }

    private static function normalizeRule(string $rule): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $rule)));
    }
}
