<?php

declare(strict_types=1);

namespace Inlay\AuthorizationSpatie\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

interface TeamResolver
{
    public function resolve(Request $request): int|string|Model|null;
}
