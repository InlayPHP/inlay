<?php

use Inlay\AuthorizationSpatie\TeamResolvers\NullTeamResolver;

return [
    'super_admin_role' => 'super-admin',
    'default_guard' => 'web',
    'teams' => [
        'resolver' => NullTeamResolver::class,
        'session_key' => 'inlay_team_id',
        'user_relations' => ['roles', 'permissions'],
    ],
];
