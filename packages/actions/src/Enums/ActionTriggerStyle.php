<?php

declare(strict_types=1);

namespace Inlay\Actions\Enums;

enum ActionTriggerStyle: string
{
    case Button = 'button';
    case Link = 'link';
    case IconButton = 'icon-button';
    case Badge = 'badge';
}
