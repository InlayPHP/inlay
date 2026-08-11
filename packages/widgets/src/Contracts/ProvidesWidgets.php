<?php

declare(strict_types=1);

namespace Inlay\Widgets\Contracts;

use Illuminate\Http\Request;

interface ProvidesWidgets
{
    /** @return iterable<\Inlay\Widgets\Widget> */
    public function widgets(Request $request): iterable;
}
