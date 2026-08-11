<?php

declare(strict_types=1);

namespace Inlay\Validation;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class ValidationRunner
{
    public function __construct(
        private readonly Factory $factory,
        private readonly ?Container $container = null,
    ) {}

    /**
     * @param  Validation|class-string<Validation>  $validation
     * @param  array<string, mixed>  $data
     */
    public function make(
        Validation|string $validation,
        array $data,
        ?ValidationContext $context = null,
    ): Validator {
        $validation = $this->resolveValidation($validation);
        $context ??= ValidationContext::make();
        $context = $context->withData($data);
        $prepared = $validation->prepare($data, $context);
        $context = $context->withData($prepared);

        $validator = $this->factory->make(
            $prepared,
            $validation->rules($context),
            $validation->messages($context),
            $validation->attributes($context),
        );

        foreach ($validation->after($context) as $callback) {
            $validator->after($callback);
        }

        if ($validation->stopOnFirstFailure($context)) {
            $validator->stopOnFirstFailure();
        }

        return $validator;
    }

    /**
     * @param  Validation|class-string<Validation>  $validation
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validate(
        Validation|string $validation,
        array $data,
        ?ValidationContext $context = null,
    ): array {
        return $this->make($validation, $data, $context)->validate();
    }

    /** @param Validation|class-string<Validation> $validation */
    private function resolveValidation(Validation|string $validation): Validation
    {
        if ($validation instanceof Validation) {
            return $validation;
        }

        if (! is_subclass_of($validation, Validation::class)) {
            throw new InvalidArgumentException("Validation class [{$validation}] must extend ".Validation::class.'.');
        }

        $resolved = $this->container?->make($validation) ?? new $validation;

        if (! $resolved instanceof Validation) {
            throw new InvalidArgumentException("Unable to resolve validation class [{$validation}].");
        }

        return $resolved;
    }
}
