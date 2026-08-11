<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Inlay\Installer\InstallCommand;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function runInlayInstall(string $root, array $arguments = []): int
{
    $files = new Filesystem;
    if (! $files->exists($root.'/composer.json')) {
        $files->put($root.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));
    }
    $app = new Application($root);
    $app->useAppPath($root.'/app');

    $command = new InstallCommand($files);
    $command->setLaravel($app);
    $console = new ConsoleApplication;
    $console->setAutoExit(false);
    $console->add($command);

    return $console->run(new ArrayInput(['command' => 'inlay:install', ...$arguments]), new BufferedOutput);
}

it('scaffolds a default panel provider and registers it in the panel config', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-install-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app');

        expect(runInlayInstall($root))->toBe(0)
            ->and($files->get($root.'/app/Providers/Inlay/AdminPanelProvider.php'))
            ->toContain('namespace App\\Providers\\Inlay;')
            ->toContain("->path('/admin')")
            ->toContain("return 'admin';")
            ->and($files->get($root.'/config/inlay-panels.php'))
            ->toContain('App\\Providers\\Inlay\\AdminPanelProvider::class');

        expect(runInlayInstall($root))->toBe(1);
        expect(runInlayInstall($root, ['--force' => true, '--renderer' => 'vue']))->toBe(0)
            ->and($files->get($root.'/app/Providers/Inlay/AdminPanelProvider.php'))
            ->toContain("->renderComponent('PanelLayout')");
    } finally {
        $files->deleteDirectory($root);
    }
});

it('adds a provider to an existing config without replacing application settings', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-install-config-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app');
        $files->ensureDirectoryExists($root.'/config');
        $files->put($root.'/config/inlay-panels.php', "<?php\nreturn [\n    'providers' => [\n        App\\Providers\\Inlay\\ExistingPanelProvider::class,\n    ],\n    'custom' => true,\n];\n");

        expect(runInlayInstall($root, ['--panel' => 'reports', '--no-panel' => true]))->toBe(0)
            ->and($files->get($root.'/config/inlay-panels.php'))
            ->toContain('ExistingPanelProvider::class')
            ->toContain('App\\Providers\\Inlay\\ReportsPanelProvider::class')
            ->toContain("'custom' => true");
    } finally {
        $files->deleteDirectory($root);
    }
});

it('scaffolds a tenant-aware panel provider when a tenant model is supplied', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-install-tenant-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app');

        expect(runInlayInstall($root, [
            '--panel' => 'workspace',
            '--tenant-model' => 'App\\Models\\Team',
            '--tenant-parameter' => 'team',
            '--tenant-route-key' => 'slug',
        ]))->toBe(0);

        expect($files->get($root.'/app/Providers/Inlay/WorkspacePanelProvider.php'))
            ->toContain('use App\\Models\\Team;')
            ->toContain("->tenant(Team::class, parameter: 'team', routeKey: 'slug')")
            ->toContain("->path('/workspace')");
    } finally {
        $files->deleteDirectory($root);
    }
});

it('rejects unsafe panel ids and output paths', function (array $arguments): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-install-invalid-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app');

        expect(runInlayInstall($root, $arguments))->toBe(1);
        expect($files->exists($root.'/config/inlay-panels.php'))->toBeFalse();
    } finally {
        $files->deleteDirectory($root);
    }
})->with([
    [['--panel' => '../admin']],
    [['--renderer' => 'svelte']],
    [['--tenant-model' => 'App\\Models\\Team;']],
    [['--tenant-parameter' => '../team']],
    [['--tenant-route-key' => 'route.key']],
    [['--path' => '../Outside']],
]);
