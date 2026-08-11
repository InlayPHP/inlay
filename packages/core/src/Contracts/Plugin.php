<?php

declare(strict_types=1);

namespace Inlay\Core\Contracts;

use Inlay\Core\PluginContext;

interface Plugin
{
    public function id(): string;

    public function register(PluginContext $context): void;

    public function boot(PluginContext $context): void;
}
