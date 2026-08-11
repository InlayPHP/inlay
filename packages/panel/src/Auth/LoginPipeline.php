<?php

declare(strict_types=1);

namespace Inlay\Auth;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class LoginPipeline
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param iterable<LoginStep|class-string<LoginStep>> $steps
     */
    public function process(LoginAttempt $attempt, iterable $steps): ?Response
    {
        $resolved = [];
        foreach ($steps as $step) {
            $instance = is_string($step) ? $this->container->make($step) : $step;
            if (! $instance instanceof LoginStep) {
                throw new InvalidArgumentException('Panel login pipeline steps must implement '.LoginStep::class.'.');
            }
            $resolved[] = $instance;
        }

        $index = 0;
        $next = function () use (&$index, $resolved, $attempt, &$next): ?Response {
            $step = $resolved[$index++] ?? null;
            if ($step === null) {
                return null;
            }

            $response = $step->handle($attempt, $next);
            if ($response !== null && ! $response instanceof Response) {
                throw new \UnexpectedValueException('Panel login pipeline steps must return a Symfony response or null.');
            }

            return $response;
        };

        return $next();
    }
}
