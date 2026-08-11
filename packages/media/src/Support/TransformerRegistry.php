<?php

declare(strict_types=1);

namespace Inlay\Media\Support;

use Inlay\Media\Contracts\MediaAssetContract;
use Inlay\Media\Contracts\MediaTransformer;

final class TransformerRegistry
{
    /** @var list<MediaTransformer> */
    private array $transformers = [];

    public function register(MediaTransformer $transformer): self
    {
        $this->transformers[] = $transformer;

        return $this;
    }

    public function run(MediaAssetContract $asset): void
    {
        foreach ($this->transformers as $transformer) {
            if ($transformer->supports($asset)) {
                $transformer->transform($asset);
            }
        }
    }
}
