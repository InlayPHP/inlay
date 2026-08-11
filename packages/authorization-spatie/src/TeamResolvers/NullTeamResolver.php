<?php

declare(strict_types=1);

namespace Inlay\AuthorizationSpatie\TeamResolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inlay\AuthorizationSpatie\Contracts\TeamResolver;

final class NullTeamResolver implements TeamResolver
{
    public function resolve(Request $request): int|string|Model|null
    {
        return null;
    }
}
