<?php

declare(strict_types=1);

namespace Inlay\Contracts;

use Inlay\Panel;

interface PanelUser
{
    public function canAccessPanel(Panel $panel): bool;
}
