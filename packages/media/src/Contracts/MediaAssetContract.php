<?php

declare(strict_types=1);

namespace Inlay\Media\Contracts;

use Inlay\Media\Enums\MediaVisibility;

interface MediaAssetContract
{
    public function key(): int|string|null;

    public function disk(): string;

    public function path(): string;

    public function mimeType(): string;

    public function size(): int;

    public function visibility(): MediaVisibility;

    /** @return array<string, mixed> */
    public function metadata(): array;
}
