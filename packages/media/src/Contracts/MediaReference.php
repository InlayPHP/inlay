<?php

declare(strict_types=1);

namespace Inlay\Media\Contracts;

use InvalidArgumentException;
use JsonSerializable;

final readonly class MediaReference implements JsonSerializable
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public string $type,
        public string $label,
        public ?string $url = null,
        public array $meta = [],
    ) {
        if (trim($this->type) === '' || trim($this->label) === '') {
            throw new InvalidArgumentException('A media reference type and label are required.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'url' => $this->url,
            'meta' => $this->meta,
        ];
    }
}
