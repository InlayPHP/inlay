<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields\FileUpload;

use Inlay\Support\SafeUrl;
use JsonSerializable;

/**
 * Renderer-safe metadata for a file that already belongs to the application.
 *
 * The opaque ID is submitted back to Laravel. Storage paths and authorization
 * decisions stay on the server; expose only signed or otherwise authorized URLs.
 */
final class FileUploadEntry implements JsonSerializable
{
    private ?string $previewUrl = null;

    private ?string $openUrl = null;

    private ?string $downloadUrl = null;

    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly int $size,
        private readonly string $mimeType,
    ) {
        if (trim($id) === '' || trim($name) === '') {
            throw new \InvalidArgumentException('Existing upload IDs and names cannot be empty.');
        }

        if ($size < 0) {
            throw new \InvalidArgumentException('Existing upload sizes cannot be negative.');
        }

        if (! preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#i', $mimeType)) {
            throw new \InvalidArgumentException("Invalid MIME type [{$mimeType}].");
        }
    }

    public static function make(string $id, string $name, int $size, string $mimeType): self
    {
        return new self($id, $name, $size, $mimeType);
    }

    /** The stored identifier this entry represents. */
    public function id(): string
    {
        return $this->id;
    }

    public function previewUrl(string $url): self
    {
        $this->previewUrl = SafeUrl::from($url)->value();

        return $this;
    }

    public function openUrl(string $url): self
    {
        $this->openUrl = SafeUrl::from($url)->value();

        return $this;
    }

    public function downloadUrl(string $url): self
    {
        $this->downloadUrl = SafeUrl::from($url)->value();

        return $this;
    }

    /** @return array{id: string, name: string, size: int, mimeType: string, previewUrl: ?string, openUrl: ?string, downloadUrl: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'mimeType' => $this->mimeType,
            'previewUrl' => $this->previewUrl,
            'openUrl' => $this->openUrl,
            'downloadUrl' => $this->downloadUrl,
        ];
    }
}
