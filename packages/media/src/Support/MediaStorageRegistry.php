<?php

declare(strict_types=1);

namespace Inlay\Media\Support;

use Inlay\Media\Contracts\MediaStorageBrowser;
use Inlay\Media\Contracts\MediaStorageObject;
use InvalidArgumentException;

final class MediaStorageRegistry
{
    /** @var array<string, MediaStorageBrowser> */
    private array $browsers = [];

    public function register(string $name, MediaStorageBrowser $browser): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('A storage browser name is required.');
        }
        if (isset($this->browsers[$name])) {
            throw new InvalidArgumentException("Storage browser [{$name}] is already registered.");
        }

        $this->browsers[$name] = $browser;

        return $this;
    }

    /** @return array<string, MediaStorageBrowser> */
    public function all(): array
    {
        ksort($this->browsers);

        return $this->browsers;
    }

    public function browser(string $name): MediaStorageBrowser
    {
        return $this->browsers[$name] ?? throw new InvalidArgumentException("Storage browser [{$name}] is not registered.");
    }

    /** @return list<MediaStorageObject> */
    public function browse(string $name, string $disk, string $prefix = '', int $limit = 100): array
    {
        $browser = $this->browser($name);
        $objects = [];

        $limit = max(1, min($limit, 500));
        foreach ($browser->browse($disk, $prefix, $limit) as $object) {
            if (! $object instanceof MediaStorageObject) {
                throw new InvalidArgumentException('A storage browser must return MediaStorageObject values.');
            }

            $objects[] = $object;
            if (count($objects) >= $limit) {
                break;
            }
        }

        return $objects;
    }
}
