<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

final class ConsoleCommandRegistrar
{
    public static function add(Application $application, Command $command): void
    {
        if (method_exists($application, 'addCommand')) {
            $application->addCommand($command);

            return;
        }

        $application->add($command);
    }
}
