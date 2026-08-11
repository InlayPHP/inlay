<?php

declare(strict_types=1);

namespace Inlay\Core;

use Inlay\Core\Contracts\Plugin;
use InvalidArgumentException;
use LogicException;

final class PluginManager
{
    private readonly ContractVersion $coreVersion;

    /** @var array<string, Plugin> */
    private array $plugins = [];

    /** @var array<string, ExtensionManifest> */
    private array $manifests = [];

    private bool $booted = false;

    private bool $bootStarted = false;

    /** @var array<string, true> */
    private array $bootedPlugins = [];

    public function __construct(ContractVersion|string $coreVersion, private readonly PluginContext $context)
    {
        $this->coreVersion = is_string($coreVersion) ? ContractVersion::from($coreVersion) : $coreVersion;
    }

    public function register(Plugin $plugin, ?ExtensionManifest $manifest = null): self
    {
        if ($this->bootStarted) {
            throw new LogicException('Plugins cannot be registered after the plugin manager has booted.');
        }

        $id = trim($plugin->id());

        if ($id === '') {
            throw new InvalidArgumentException('A plugin ID cannot be empty.');
        }

        if (isset($this->plugins[$id])) {
            throw new InvalidArgumentException("Plugin [{$id}] is already registered.");
        }

        $manifest ??= ExtensionManifest::make($id);

        if ($manifest->id !== $id) {
            throw new InvalidArgumentException("Plugin ID [{$id}] does not match manifest ID [{$manifest->id}].");
        }

        $manifest->assertCompatibleWith($this->coreVersion);

        $checkpoints = [
            $this->context->extensions()->checkpoint(),
            $this->context->assets()->checkpoint(),
            $this->context->renderHooks()->checkpoint(),
        ];

        try {
            $plugin->register($this->context);
        } catch (\Throwable $exception) {
            foreach (array_reverse($checkpoints) as $checkpoint) {
                $checkpoint->rollback();
            }

            throw $exception;
        }

        foreach ($checkpoints as $checkpoint) {
            $checkpoint->commit();
        }

        $this->plugins[$id] = $plugin;
        $this->manifests[$id] = $manifest;

        return $this;
    }

    /** @param iterable<Plugin|array{0: Plugin, 1: ExtensionManifest}> $plugins */
    public function load(iterable $plugins): self
    {
        foreach ($plugins as $plugin) {
            if (is_array($plugin)) {
                $this->register($plugin[0], $plugin[1]);
            } else {
                $this->register($plugin);
            }
        }

        return $this->boot();
    }

    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->bootStarted = true;

        foreach ($this->plugins as $id => $plugin) {
            if (isset($this->bootedPlugins[$id])) {
                continue;
            }

            $plugin->boot($this->context);
            $this->bootedPlugins[$id] = true;
        }

        $this->booted = true;

        return $this;
    }

    public function has(string $id): bool
    {
        return isset($this->plugins[$id]);
    }

    public function plugin(string $id): Plugin
    {
        return $this->plugins[$id] ?? throw new InvalidArgumentException("Plugin [{$id}] is not registered.");
    }

    /** @return list<Plugin> */
    public function plugins(): array
    {
        return array_values($this->plugins);
    }

    public function manifest(string $id): ExtensionManifest
    {
        return $this->manifests[$id] ?? throw new InvalidArgumentException("Plugin [{$id}] is not registered.");
    }

    public function context(): PluginContext
    {
        return $this->context;
    }

    public function coreVersion(): ContractVersion
    {
        return $this->coreVersion;
    }
}
