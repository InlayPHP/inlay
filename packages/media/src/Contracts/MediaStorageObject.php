<?php

declare(strict_types=1);

namespace Inlay\Media\Contracts;

use JsonSerializable;

final readonly class MediaStorageObject implements JsonSerializable
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $name,
        public bool $directory = false,
        public ?string $mimeType = null,
        public ?int $size = null,
        public ?int $lastModified = null,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'disk' => $this->disk,
            'path' => $this->path,
            'name' => $this->name,
            'directory' => $this->directory,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'last_modified' => $this->lastModified,
        ];
    }
}
