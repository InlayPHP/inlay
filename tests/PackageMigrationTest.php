<?php

declare(strict_types=1);

use Inlay\Admin\AdminServiceProvider;
use Inlay\Admin\Routing\AdminPanelRegistrar;
use Inlay\Contracts\PanelUser;
use Inlay\Core\Contracts\Plugin;
use Inlay\MediaManager\MediaManagerPlugin;
use Inlay\MediaManager\MediaManagerServiceProvider;
use Inlay\Panel;
use Inlay\PanelProvider;
use Inlay\PanelRegistry;
use Inlay\PanelRoute;
use Inlay\PanelServiceProvider;
use Inlay\PermissionManager\PermissionManagerPlugin;
use Inlay\PermissionManager\PermissionManagerServiceProvider;
use Inlay\Routing\PanelRegistrar;

it('publishes the framework and every component under the inlayphp composer vendor', function () {
    $root = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($root['name'])->toBe('inlayphp/inlay')
        ->and($root['require'])->not->toHaveKey('inlayphp/admin');

    $packages = glob(__DIR__.'/../packages/*/composer.json') ?: [];
    expect($packages)->not->toBeEmpty();

    foreach ($packages as $manifest) {
        $package = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        expect($package['name'])->toStartWith('inlayphp/');

        foreach (array_keys($package['require'] ?? []) as $dependency) {
            if (str_starts_with($dependency, 'inlay')) {
                expect($dependency)->toStartWith('inlayphp/');
            }
        }
    }
});

it('keeps every Composer package on the PHP 8.3 and Laravel 12 platform floor', function (): void {
    $root = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifests = array_merge(
        [__DIR__.'/../composer.json'],
        glob(__DIR__.'/../packages/*/composer.json') ?: [],
    );
    $violations = [];

    foreach ($manifests as $manifestPath) {
        $manifest = $manifestPath === __DIR__.'/../composer.json'
            ? $root
            : json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $requires = $manifest['require'] ?? [];

        if (($requires['php'] ?? null) !== '^8.3') {
            $violations[] = basename(dirname($manifestPath)).': PHP must be ^8.3';
        }

        foreach ($requires as $dependency => $constraint) {
            if (! ($dependency === 'laravel/framework' || str_starts_with($dependency, 'illuminate/'))) {
                continue;
            }

            if (! is_string($constraint) || ! str_contains($constraint, '^12.0')) {
                $violations[] = basename(dirname($manifestPath)).": {$dependency} must support Laravel 12";
            }
        }
    }

    expect($violations)->toBe([], 'Package platform constraints must remain aligned with the supported Laravel baseline.');
});

it('keeps the media asset uniqueness key within MySQL index limits', function (): void {
    $migration = file_get_contents(__DIR__.'/../packages/media/database/migrations/2026_01_01_000000_create_inlay_media_tables.php');

    expect($migration)
        ->toContain("if (! Schema::hasTable('inlay_media_folders'))")
        ->toContain("if (! Schema::hasTable('inlay_media_assets'))")
        ->toContain('$table->string(\'disk\', 50);')
        ->toContain('$table->string(\'path\', 500);')
        ->not->toContain('$table->string(\'path\', 1024);');
});

it('coordinates every public non-CMS package on the 0.3 release line', function (): void {
    $composerManifests = array_merge(
        [__DIR__.'/../composer.json'],
        glob(__DIR__.'/../packages/*/composer.json') ?: [],
    );

    foreach ($composerManifests as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $name = (string) ($manifest['name'] ?? '');

        if ($name === 'inlayphp/cms' || str_starts_with($name, 'inlayphp/cms-')) {
            continue;
        }

        foreach ([...($manifest['require'] ?? []), ...($manifest['require-dev'] ?? [])] as $dependency => $constraint) {
            if (str_starts_with((string) $dependency, 'inlayphp/')) {
                expect($constraint)->toBe('^0.3 || dev-main', "{$name} must use the coordinated Composer release line.");
            }
        }
    }

    foreach (glob(__DIR__.'/../packages/*/*/package.json') ?: [] as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $name = (string) ($manifest['name'] ?? '');

        if (! str_starts_with($name, '@inlayphp/') || str_starts_with($name, '@inlayphp/cms')) {
            continue;
        }

        expect($manifest['version'] ?? null)->toBe('0.3.4', "{$name} must use the coordinated npm release line.");

        foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $section) {
            foreach ($manifest[$section] ?? [] as $dependency => $constraint) {
                if (str_starts_with((string) $dependency, '@inlayphp/')) {
                    expect($constraint)->toBe('workspace:^', "{$name} must use coordinated internal npm ranges.");
                }
            }
        }
    }
});

it('keeps the root panel preset complete while leaving advanced plugins optional', function () {
    $root = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $core = [
        'inlayphp/actions',
        'inlayphp/authorization',
        'inlayphp/core',
        'inlayphp/design',
        'inlayphp/forms',
        'inlayphp/infolists',
        'inlayphp/media',
        'inlayphp/media-manager',
        'inlayphp/notifications',
        'inlayphp/panels',
        'inlayphp/resources',
        'inlayphp/schemas',
        'inlayphp/support',
        'inlayphp/tables',
        'inlayphp/theme',
        'inlayphp/validation',
        'inlayphp/widgets',
    ];
    $plugins = [
        'inlayphp/authorization-spatie',
        'inlayphp/imports',
        'inlayphp/media-spatie',
        'inlayphp/permission-manager',
        'inlayphp/tables-xlsx',
        'inlayphp/two-factor-authentication',
    ];

    expect($root['require'])->toHaveKeys($core);

    foreach ($plugins as $plugin) {
        expect($root['require'])->not->toHaveKey($plugin)
            ->and($root['require-dev'])->toHaveKey($plugin)
            ->and($root['suggest'])->toHaveKey($plugin);
    }
});

it('does not make clean core components depend on optional plugins', function () {
    // Optional packages may depend on each other freely, but nothing in the
    // clean core may depend on them.
    $pluginNames = [
        'inlayphp/authorization-spatie',
        'inlayphp/imports',
        'inlayphp/media',
        'inlayphp/media-manager',
        'inlayphp/media-spatie',
        'inlayphp/permission-manager',
        'inlayphp/two-factor-authentication',
    ];
    $pluginDirectories = [
        'authorization-spatie', 'import', 'media', 'media-manager', 'media-spatie',
        'permission-manager',
        'table-xlsx',
        'two-factor-authentication',
    ];

    foreach (glob(__DIR__.'/../packages/*/composer.json') ?: [] as $manifestPath) {
        if (in_array(basename(dirname($manifestPath)), $pluginDirectories, true)) {
            continue;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $dependencies = array_keys($manifest['require'] ?? []);

        expect(array_intersect($dependencies, $pluginNames))
            ->toBe([], "Core package [{$manifest['name']}] must not require an optional plugin.");
    }
});

it('declares complete standalone permission and media plugin dependencies', function () {
    $permission = json_decode((string) file_get_contents(__DIR__.'/../packages/permission-manager/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $media = json_decode((string) file_get_contents(__DIR__.'/../packages/media-manager/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($permission['require'])->toHaveKeys([
        'inlayphp/authorization-spatie',
        'inlayphp/panels',
        'inlayphp/resources',
    ])->and($permission['extra']['laravel']['providers'])->toBe([
        PermissionManagerServiceProvider::class,
    ])->and(is_subclass_of(
        PermissionManagerPlugin::class,
        Plugin::class,
    ))->toBeTrue()
        ->and($media['require'])->toHaveKeys([
            'inlayphp/media',
            'inlayphp/panels',
        ])->and($media['extra']['laravel']['providers'])->toBe([
            MediaManagerServiceProvider::class,
        ])->and(is_subclass_of(
            MediaManagerPlugin::class,
            Plugin::class,
        ))->toBeTrue();
});

it('removes the standalone admin package after merging its runtime into panels', function () {
    expect(is_dir(__DIR__.'/../packages/admin'))->toBeFalse()
        ->and(class_exists(AdminServiceProvider::class))->toBeFalse()
        ->and(class_exists(AdminPanelRegistrar::class))->toBeFalse()
        ->and(class_exists(PanelRegistrar::class))->toBeTrue();
});

it('exposes the primary panel API from the flat Inlay namespace', function () {
    expect(class_exists(Panel::class))->toBeTrue()
        ->and(class_exists(PanelProvider::class))->toBeTrue()
        ->and(class_exists(PanelRegistry::class))->toBeTrue()
        ->and(class_exists(PanelRoute::class))->toBeTrue()
        ->and(class_exists(PanelServiceProvider::class))->toBeTrue()
        ->and(interface_exists(PanelUser::class))->toBeTrue()
        ->and(class_exists(Inlay\Panels\Panel::class))->toBeFalse();
});

it('declares the flattened namespace and consolidated provider in the panels manifest', function () {
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../packages/panel/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['autoload']['psr-4'])->toBe(['Inlay\\' => 'src/'])
        ->and($manifest['extra']['laravel']['providers'])->toBe([PanelServiceProvider::class])
        ->and($manifest['require'])->toHaveKeys([
            'illuminate/auth',
            'illuminate/http',
            'illuminate/routing',
            'inertiajs/inertia-laravel',
        ]);
});

it('locks only the renamed Inlay Composer packages in the monorepo and playground', function () {
    $locks = [
        __DIR__.'/../composer.lock',
        __DIR__.'/../playground/laravel-react/composer.lock',
    ];

    foreach ($locks as $lockPath) {
        $lock = json_decode((string) file_get_contents($lockPath), true, flags: JSON_THROW_ON_ERROR);
        $packages = [...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])];
        $names = array_column($packages, 'name');
        $inlayPackages = array_values(array_filter(
            $names,
            static fn (string $name): bool => str_starts_with($name, 'inlay'),
        ));

        expect($inlayPackages)->not->toBeEmpty();

        foreach ($inlayPackages as $name) {
            expect($name)->toStartWith('inlayphp/')
                ->and($name)->not->toBe('inlayphp/admin');
        }

        expect($names)->toContain('inlayphp/panels')
            ->and($names)->toContain('inlayphp/resources');
    }
});

it('autoloads the resource testing DSL in package manifests and installed locks', function () {
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../packages/resources/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['autoload']['files'])->toBe(['src/Testing/functions.php'])
        ->and(function_exists('inlay'))->toBeTrue();

    foreach ([__DIR__.'/../composer.lock', __DIR__.'/../playground/laravel-react/composer.lock'] as $lockPath) {
        $lock = json_decode((string) file_get_contents($lockPath), true, flags: JSON_THROW_ON_ERROR);
        $packages = [...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])];
        $resources = collect($packages)->firstWhere('name', 'inlayphp/resources');

        expect($resources)->not->toBeNull()
            ->and($resources['autoload']['files'] ?? null)->toBe(['src/Testing/functions.php']);
    }
});

it('locks the rich content sanitizer above the patched security baseline', function () {
    $forms = json_decode((string) file_get_contents(__DIR__.'/../packages/form/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($forms['require']['symfony/html-sanitizer'])->toBe('^7.4.13');

    foreach ([__DIR__.'/../composer.lock', __DIR__.'/../playground/laravel-react/composer.lock'] as $lockPath) {
        $lock = json_decode((string) file_get_contents($lockPath), true, flags: JSON_THROW_ON_ERROR);
        $packages = [...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])];
        $sanitizer = collect($packages)->firstWhere('name', 'symfony/html-sanitizer');

        expect($sanitizer)->not->toBeNull()
            ->and(version_compare(ltrim((string) $sanitizer['version'], 'v'), '7.4.13', '>='))->toBeTrue();
    }
});
