<?php

declare(strict_types=1);

namespace Inlay\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;

final readonly class AuthorizationManager
{
    public function __construct(
        private Gate $gate,
        private AbilityRegistry $abilities,
    ) {}

    public function abilities(): AbilityRegistry
    {
        return $this->abilities;
    }

    public function allows(mixed $user, string $ability, mixed $arguments = []): bool
    {
        return $user !== null && $this->gate->forUser($user)->allows($ability, $arguments);
    }

    public function inspect(mixed $user, string $ability, mixed $arguments = []): Response
    {
        if ($user === null) {
            return Response::deny('Authentication is required.');
        }

        return $this->gate->forUser($user)->inspect($ability, $arguments);
    }

    public function authorize(mixed $user, string $ability, mixed $arguments = []): Response
    {
        if ($user === null) {
            return Response::deny('Authentication is required.')->authorize();
        }

        return $this->gate->forUser($user)->authorize($ability, $arguments);
    }
}
