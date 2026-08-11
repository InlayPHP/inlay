<?php

declare(strict_types=1);

namespace Inlay\Media\Contracts;

interface MediaTransformer
{
    public function supports(MediaAssetContract $asset): bool;

    public function transform(MediaAssetContract $asset): void;
}
