<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

final class CheckboxList extends OptionsField
{
    protected function type(): string
    {
        return 'checkbox-list';
    }
}
