<?php

declare(strict_types=1);

namespace Inlay\Auth;

use Illuminate\Http\Request;
use Inlay\Panel;

/**
 * State passed through the post-credential panel login pipeline.
 *
 * Credentials have already been verified and the panel access check has
 * passed. A step may return a response (for example, a two-factor challenge)
 * or call the next step to allow the normal redirect to continue.
 */
final readonly class LoginAttempt
{
    public function __construct(
        public Request $request,
        public Panel $panel,
        public object $user,
        public bool $remember,
    ) {}
}
