<?php

declare(strict_types=1);

namespace Inlay\Media\Contracts;

interface MediaStorageBrowser
{
    /** @return array<string, string> disk name to display label */
    public function disks(): array;

    /** @return iterable<MediaStorageObject> */
    public function browse(string $disk, string $prefix, int $limit): iterable;
}
