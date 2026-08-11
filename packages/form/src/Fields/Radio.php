<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

final class Radio extends OptionsField
{
    protected function type(): string
    {
        return 'radio';
    }
}
