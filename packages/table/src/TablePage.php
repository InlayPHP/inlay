<?php

declare(strict_types=1);

namespace Inlay\Tables;

use Illuminate\Http\Request;
use Inlay\Tables\Concerns\InteractsWithTables;
use Inlay\Tables\Contracts\HasTables;

abstract class TablePage implements HasTables
{
    use InteractsWithTables;

    protected static string $component;

    final public static function component(): string
    {
        if (! isset(static::$component) || trim(static::$component) === '') {
            throw new \LogicException('Standalone table pages must declare a non-empty static $component.');
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
