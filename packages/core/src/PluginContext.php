<?php

declare(strict_types=1);

namespace Inlay\Core;

use InvalidArgumentException;

final readonly class PluginContext
{
    public function __construct(
        private object $host,
        private ExtensionRegistry $extensions,
        private AssetRegistry $assets,
        private RenderHookRegistry $renderHooks,
    ) {
    }

    public function host(): object
    {
        return $this->host;
    }

    /**
     * @template THost of object
     * @param class-string<THost> $class
     * @return THost
     */
    public function hostAs(string $class): object
    {
        if (! $this->host instanceof $class) {
            throw new InvalidArgumentException("Plugin host must be an instance of [{$class}].");
        }

        return $this->host;
    }

    public function extensions(): ExtensionRegistry
    {
        return $this->extensions;
    }

    public function assets(): AssetRegistry
    {
        return $this->assets;
    }

    public function renderHooks(): RenderHookRegistry
    {
        return $this->renderHooks;
    }
}
