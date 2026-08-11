<?php

declare(strict_types=1);

namespace Inlay\Widgets\Contracts;

use DateInterval;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Opts a request-aware widget provider into server-side result caching.
 *
 * The key is deliberately supplied by the application. Include every scope
 * that can change the result (for example user, tenant, locale, and date
 * range); a cache entry never replaces authorization or query scoping.
 */
interface CacheableWidgets extends ProvidesWidgets
{
    public function cacheKey(Request $request): string;

    public function cacheTtl(Request $request): int|DateInterval|DateTimeInterface|null;
}
