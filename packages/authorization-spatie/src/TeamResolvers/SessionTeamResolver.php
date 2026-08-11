<?php

declare(strict_types=1);

namespace Inlay\AuthorizationSpatie\TeamResolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inlay\AuthorizationSpatie\Contracts\TeamResolver;

final class SessionTeamResolver implements TeamResolver
{
    public function resolve(Request $request): int|string|Model|null
    {
        if (! $request->hasSession()) {
            return null;
        }

        $key = config('inlay-authorization-spatie.teams.session_key', 'inlay_team_id');
        $team = $request->session()->get(is_string($key) && $key !== '' ? $key : 'inlay_team_id');

        return is_int($team) || is_string($team) || $team instanceof Model ? $team : null;
    }
}
