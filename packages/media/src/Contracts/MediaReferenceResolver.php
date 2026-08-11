<?php

declare(strict_types=1);

namespace Inlay\Media\Contracts;

interface MediaReferenceResolver
{
    /** @return iterable<MediaReference> */
    public function resolve(MediaAssetContract $asset): iterable;
}
