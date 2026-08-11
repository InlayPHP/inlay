<?php

declare(strict_types=1);

namespace Inlay\Tables\Enums;

enum ColumnManagerResetActionPosition: string
{
    case Header = 'header';
    case Footer = 'footer';
}
