<?php

declare(strict_types=1);

return [
    'issuer' => env('INLAY_TWO_FACTOR_ISSUER', env('APP_NAME', 'Inlay')),
    'digits' => 6,
    'period' => 30,
    'window' => 1,
    'recovery_codes' => 8,
    'pending_session' => 'inlay.two-factor.pending',
    'challenge_path' => 'two-factor-challenge',
    'table' => 'users',
    'secret_column' => 'two_factor_secret',
    'recovery_codes_column' => 'two_factor_recovery_codes',
    'confirmed_at_column' => 'two_factor_confirmed_at',
];
