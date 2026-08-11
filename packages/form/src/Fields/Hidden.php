<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Inlay\Forms\Field;

final class Hidden extends Field
{
    protected function type(): string
    {
        return 'hidden';
    }
}
