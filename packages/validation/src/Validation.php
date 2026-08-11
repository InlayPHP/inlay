<?php

declare(strict_types=1);

namespace Inlay\Validation;

use Illuminate\Validation\Validator;

/**
 * Base class for validation definitions owned by the consuming application.
 *
 * This package supplies the lifecycle only. Concrete validation classes and their
 * domain rules belong under the application's own namespace.
 */
abstract class Validation
{
    /** @return array<string, mixed> */
    abstract public function rules(ValidationContext $context): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(array $data, ValidationContext $context): array
    {
        return $data;
    }

    /** @return array<string, string> */
    public function messages(ValidationContext $context): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function attributes(ValidationContext $context): array
    {
        return [];
    }

    /** @return list<callable(Validator): void> */
    public function after(ValidationContext $context): array
    {
        return [];
    }

    public function stopOnFirstFailure(ValidationContext $context): bool
    {
        return false;
    }
}
