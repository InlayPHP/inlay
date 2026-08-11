<?php

declare(strict_types=1);

namespace Inlay\Media\Support;

use Inlay\Media\Contracts\MediaAssetContract;
use Inlay\Media\Contracts\MediaReference;
use Inlay\Media\Contracts\MediaReferenceResolver;
use InvalidArgumentException;

final class MediaReferenceRegistry
{
    /** @var array<string, MediaReferenceResolver> */
    private array $resolvers = [];

    public function register(string $name, MediaReferenceResolver $resolver): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('A media reference resolver name is required.');
        }
        if (isset($this->resolvers[$name])) {
            throw new InvalidArgumentException("Media reference resolver [{$name}] is already registered.");
        }

        $this->resolvers[$name] = $resolver;

        return $this;
    }

    /** @return array<string, MediaReferenceResolver> */
    public function all(): array
    {
        ksort($this->resolvers);

        return $this->resolvers;
    }

    /** @return list<MediaReference> */
    public function resolve(MediaAssetContract $asset, int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $references = [];
        $seen = [];

        foreach ($this->resolvers as $resolver) {
            foreach ($resolver->resolve($asset) as $reference) {
                if (! $reference instanceof MediaReference) {
                    throw new InvalidArgumentException('A media reference resolver must return MediaReference values.');
                }

                $key = $reference->type."\0".$reference->label."\0".($reference->url ?? '');
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $references[] = $reference;
                if (count($references) >= $limit) {
                    return $references;
                }
            }
        }

        return $references;
    }
}
