<?php

declare(strict_types=1);

namespace Inlay\Core;

use InvalidArgumentException;

final class AssetRegistry
{
    /** @var array<string, Asset> */
    private array $assets = [];

    /** @var array<string, string> */
    private array $owners = [];

    /** @internal */
    public function checkpoint(): RegistryCheckpoint
    {
        $assets = $this->assets;
        $owners = $this->owners;

        return new RegistryCheckpoint(function () use ($assets, $owners): void {
            $this->assets = $assets;
            $this->owners = $owners;
        });
    }

    public function register(Asset $asset, string $owner): self
    {
        if (trim($owner) === '') {
            throw new InvalidArgumentException('An asset owner cannot be empty.');
        }

        if (isset($this->assets[$asset->id])) {
            throw new InvalidArgumentException("Asset [{$asset->id}] is already registered by [{$this->owners[$asset->id]}].");
        }

        $this->assets[$asset->id] = $asset;
        $this->owners[$asset->id] = $owner;

        return $this;
    }

    public function get(string $id): Asset
    {
        return $this->assets[$id] ?? throw new InvalidArgumentException("Asset [{$id}] is not registered.");
    }

    public function has(string $id): bool
    {
        return isset($this->assets[$id]);
    }

    /** @return list<Asset> */
    public function all(?string $kind = null): array
    {
        $assets = array_values($this->assets);

        if ($kind !== null) {
            if (! in_array($kind, [Asset::SCRIPT, Asset::STYLE], true)) {
                throw new InvalidArgumentException("Unsupported asset kind [{$kind}].");
            }

            $assets = array_values(array_filter($assets, fn (Asset $asset): bool => $asset->kind === $kind));
        }

        return $assets;
    }

    public function owner(string $id): string
    {
        return $this->owners[$id] ?? throw new InvalidArgumentException("Asset [{$id}] is not registered.");
    }
}
