<?php

use Inlay\AuthorizationSpatie\TeamResolvers\NullTeamResolver;

return [
    'super_admin_role' => env('INLAY_SUPER_ADMIN_ROLE', 'super-admin'),
    'default_guard' => env('INLAY_PERMISSION_GUARD', 'web'),

    'teams' => [
        /*
         * Replace this with SessionTeamResolver or your own TeamResolver implementation.
         * Keep the middleware registered even when teams are disabled; it becomes a no-op.
         */
        'resolver' => NullTeamResolver::class,
        'session_key' => env('INLAY_PERMISSION_TEAM_SESSION_KEY', 'inlay_team_id'),
        'user_relations' => ['roles', 'permissions'],
    ],
];
