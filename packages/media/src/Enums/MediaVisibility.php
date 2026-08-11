<?php

declare(strict_types=1);

namespace Inlay\Media\Enums;

enum MediaVisibility: string
{
    case Private = 'private';
    case Public = 'public';
}
