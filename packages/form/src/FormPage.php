<?php

declare(strict_types=1);

namespace Inlay\Forms;

use Illuminate\Http\Request;
use Inlay\Forms\Concerns\InteractsWithForms;
use Inlay\Forms\Contracts\HasForms;

abstract class FormPage implements HasForms
{
    use InteractsWithForms;

    protected static string $component;

    final public static function component(): string
    {
        if (! isset(static::$component) || trim(static::$component) === '') {
            throw new \LogicException('Standalone form pages must declare a non-empty static $component.');
        }

        return static::$component;
    }

    /** @return array<string, mixed> */
    final public function resolveProps(Request $request): array
    {
        return $this->props($request);
    }

    /** @return array<string, mixed> */
    protected function props(Request $request): array
    {
        return [];
    }
}
