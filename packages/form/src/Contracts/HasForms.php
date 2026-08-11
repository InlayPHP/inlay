<?php

declare(strict_types=1);

namespace Inlay\Forms\Contracts;

use Illuminate\Http\Request;
use Inlay\Forms\Form;

interface HasForms
{
    /** @return array<string, Form> */
    public function resolveForms(Request $request): array;

    public function resolveForm(Request $request, ?string $name = null): Form;
}
