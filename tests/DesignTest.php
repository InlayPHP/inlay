<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Inlay\Design\Console\MakeThemeCommand;
use Inlay\Design\Design;
use Inlay\Theme\Theme;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixtures\ConsoleCommandRegistrar;

it('provides one PHP façade for presets, variables, and CSS output', function (): void {
    $theme = Design::default()->named('brand')->tokens([
        'control-height' => '2.75rem',
    ])->darkTokens([
        'surface' => '#17131f',
    ]);

    expect(Design::base())->toBeInstanceOf(Theme::class)
        ->and(Design::make('custom'))->toBeInstanceOf(Theme::class)
        ->and(Design::orbit()->name())->toBe('orbit')
        ->and(Design::highContrast()->name())->toBe('high-contrast')
        ->and(Design::variables(['accent' => '#7c3aed', 'enabled' => true, 'missing' => null]))
        ->toMatchArray([
            '--inlay-accent' => '#7c3aed',
            '--inlay-enabled' => 'true',
        ]);

    $css = Design::css($theme);

    expect($css)->toContain(':root {')
        ->and($css)->toContain('--inlay-accent: #5b64db;')
        ->and($css)->toContain('--inlay-control-height: 2.75rem;')
        ->and($css)->toContain('--inlay-space-card: 1.25rem;')
        ->and($css)->toContain('--inlay-focus-ring-color: rgb(142 148 229 / 0.45);')
        ->and($css)->toContain('@media (prefers-color-scheme: dark)')
        ->and($css)->toContain('[data-theme="dark"]')
        ->and($css)->toContain('--inlay-surface: #17131f;');
});

it('rejects unsafe token keys and CSS values before stylesheet generation', function (): void {
    expect(fn (): array => Design::variables(['bad;property' => 'red']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn (): array => Design::variables(['accent' => 'red; background: black']))
        ->toThrow(InvalidArgumentException::class);
});

it('generates an application theme class and CSS file without overwriting by default', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-design-generator-'.bin2hex(random_bytes(6));
    $appPath = $root.'/app';

    try {
        $files->ensureDirectoryExists($appPath);
        $files->put($root.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));

        $app = new Application($root);
        $app->useAppPath($appPath);
        $command = new MakeThemeCommand($files);
        $command->setLaravel($app);
        $console = new ConsoleApplication;
        $console->setAutoExit(false);
        ConsoleCommandRegistrar::add($console, $command);

        $status = $console->run(new ArrayInput([
            'command' => 'make:inlay-theme',
            'name' => 'Billing/Brand',
        ]), new BufferedOutput);
        $classPath = $root.'/app/Inlay/Themes/Billing/BrandTheme.php';
        $cssPath = $root.'/resources/css/inlay/billing-brand.css';

        expect($status)->toBe(0)
            ->and($files->exists($classPath))->toBeTrue()
            ->and($files->exists($cssPath))->toBeTrue()
            ->and($files->get($classPath))->toContain('namespace App\\Inlay\\Themes\\Billing;')
            ->and($files->get($classPath))->toContain('final class BrandTheme')
            ->and($files->get($classPath))->toContain("Design::default()->named('billing-brand')")
            ->and($files->get($cssPath))->toContain('--inlay-accent: #5b64db;');

        $files->append($classPath, "\n// keep me\n");
        $secondStatus = $console->run(new ArrayInput([
            'command' => 'make:inlay-theme',
            'name' => 'Billing/Brand',
        ]), new BufferedOutput);
        expect($secondStatus)->toBe(1)
            ->and($files->get($classPath))->toContain('// keep me');

        $forcedStatus = $console->run(new ArrayInput([
            'command' => 'make:inlay-theme',
            'name' => 'Billing/Brand',
            '--force' => true,
        ]), new BufferedOutput);
        expect($forcedStatus)->toBe(0)
            ->and($files->get($classPath))->not->toContain('// keep me');

        $customStatus = $console->run(new ArrayInput([
            'command' => 'make:inlay-theme',
            'name' => 'Brand',
            '--path' => 'app/Themes',
            '--css-path' => 'resources/css/themes',
        ]), new BufferedOutput);
        $customClassPath = $root.'/app/Themes/BrandTheme.php';

        expect($customStatus)->toBe(0)
            ->and($files->get($customClassPath))->toContain('namespace App\\Themes;');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('rejects traversal in generated theme paths', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-design-generator-invalid-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app');
        $app = new Application($root);
        $app->useAppPath($root.'/app');
        $command = new MakeThemeCommand($files);
        $command->setLaravel($app);
        $console = new ConsoleApplication;
        $console->setAutoExit(false);
        ConsoleCommandRegistrar::add($console, $command);

        $status = $console->run(new ArrayInput([
            'command' => 'make:inlay-theme',
            'name' => '../Outside',
        ]), new BufferedOutput);

        expect($status)->toBe(1);
    } finally {
        $files->deleteDirectory($root);
    }
});
