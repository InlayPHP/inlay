<?php

declare(strict_types=1);

namespace Inlay\MediaManager\Contracts;

use Illuminate\Http\Request;

interface BuildsMediaPayloads
{
    /** @return array<string, mixed> */
    public function build(Request $request, bool $picker = false): array;
}
