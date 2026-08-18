<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Inlay\Widgets\Contracts\CacheableWidgets;
use Inlay\Widgets\Contracts\ProvidesWidgets;
use InvalidArgumentException;
use LogicException;

final class WidgetResolver
{
    public function __construct(
        private readonly Container $container,
        private readonly ?CacheRepository $cache = null,
    ) {}

    /** @param iterable<Widget|ProvidesWidgets|class-string<ProvidesWidgets>> $sources */
    public function resolve(iterable $sources, Request $request, ?Dashboard $dashboard = null): WidgetDashboard
    {
        $widgets = [];
        foreach ($sources as $source) {
            if ($source instanceof Widget) {
                $widgets[] = $source;

                continue;
            }
            $provider = is_string($source) ? $this->container->make($source) : $source;
            if (! $provider instanceof ProvidesWidgets) {
                throw new InvalidArgumentException('Widget sources must be widgets or widget providers.');
            }
            foreach ($this->resolveProvider($provider, $request) as $widget) {
                $widgets[] = $widget;
            }
        }

        return WidgetDashboard::make()->dashboard($dashboard)->widgets($widgets);
    }

    /** @return list<Widget> */
    private function resolveProvider(ProvidesWidgets $provider, Request $request): array
    {
        if (! $provider instanceof CacheableWidgets) {
            return $this->normalize($provider->widgets($request));
        }

        if ($this->cache === null) {
            throw new LogicException('Cacheable widget providers require an Illuminate cache repository binding.');
        }

        $key = trim($provider->cacheKey($request));
        if ($key === '') {
            throw new InvalidArgumentException('A cacheable widget provider must return a non-empty cache key.');
        }

        $ttl = $provider->cacheTtl($request);
        if (is_int($ttl) && $ttl < 1) {
            throw new InvalidArgumentException('A cacheable widget provider TTL must be at least one second.');
        }

        $widgets = $this->cache->remember($key, $ttl, fn (): array => $this->normalize($provider->widgets($request)));

        return $this->normalize($widgets);
    }

    /** @param iterable<Widget>|mixed $widgets */
    private function normalize(mixed $widgets): array
    {
        if (! is_iterable($widgets)) {
            throw new InvalidArgumentException('Widget providers must return an iterable of Widget instances.');
        }

        $normalized = [];
        foreach ($widgets as $widget) {
            if (! $widget instanceof Widget) {
                throw new InvalidArgumentException('Widget providers must return Widget instances.');
            }
            $normalized[] = $widget;
        }

        return $normalized;
    }
}
