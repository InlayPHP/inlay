<?php

declare(strict_types=1);

namespace Inlay\AuthorizationSpatie\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inlay\AuthorizationSpatie\Contracts\TeamResolver;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetPermissionTeam
{
    public function __construct(
        private PermissionRegistrar $registrar,
        private TeamResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->registrar->teams) {
            return $next($request);
        }

        $previousTeam = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($this->resolver->resolve($request));
        $this->forgetUserPermissionRelations($request);

        try {
            return $next($request);
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeam);
            $this->forgetUserPermissionRelations($request);
        }
    }

    private function forgetUserPermissionRelations(Request $request): void
    {
        $user = $request->user();
        if (! is_object($user) || ! method_exists($user, 'unsetRelation')) {
            return;
        }

        $relations = config('inlay-authorization-spatie.teams.user_relations', ['roles', 'permissions']);
        if (! is_array($relations)) {
            return;
        }

        foreach ($relations as $relation) {
            if (is_string($relation) && $relation !== '') {
                $user->unsetRelation($relation);
            }
        }
    }
}
