<?php

declare(strict_types=1);

namespace Inlay\Auth;

use Closure;
use Symfony\Component\HttpFoundation\Response;

/** @phpstan-type Next Closure(): (?Response) */
interface LoginStep
{
    /**
     * Return a response to stop the pipeline, or call `$next` to continue.
     *
     * @param Closure(): (?Response) $next
     */
    public function handle(LoginAttempt $attempt, Closure $next): ?Response;
}
