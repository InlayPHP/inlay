<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory as ValidatorFactory;
use Inlay\Installer\DoctorCommand;
use Inlay\Installer\InstallCommand;
use Inlay\Installer\MakeUserCommand;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\Fixtures\ConsoleCommandRegistrar;

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
    ConsoleCommandRegistrar::add($console, $command);

    return $console->run(new ArrayInput(['command' => 'inlay:install', ...$arguments]), new BufferedOutput);
}

/** @return array{int, string} */
function runInlayDoctor(string $root, array $arguments = []): array
{
    $files = new Filesystem;
    $app = new Application($root);
    $app->useAppPath($root.'/app');

    $command = new DoctorCommand($files);
    $command->setLaravel($app);
    $console = new ConsoleApplication;
    $console->setAutoExit(false);
    ConsoleCommandRegistrar::add($console, $command);
    $output = new BufferedOutput;
    $status = $console->run(new ArrayInput(['command' => 'inlay:doctor', ...$arguments]), $output);

    return [$status, $output->fetch()];
}

final class InstallerCommandUser extends Model
{
    public $timestamps = false;

    protected $table = 'users';
}

it('scaffolds a default panel provider and registers it in the panel config', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-install-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app/Models');
        $files->ensureDirectoryExists($root.'/bootstrap');
        $files->ensureDirectoryExists($root.'/resources/css');
        $files->ensureDirectoryExists($root.'/resources/js');
        $files->ensureDirectoryExists($root.'/resources/views');
        $files->put($root.'/app/Models/User.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
}
PHP);
        $files->put($root.'/bootstrap/app.php', <<<'PHP'
<?php

use Illuminate\Foundation\Configuration\Middleware;

return Application::configure()
    ->withMiddleware(function (Middleware $middleware): void {
    });
PHP);
        $files->put($root.'/package.json', json_encode([
            'private' => true,
            'dependencies' => ['@inertiajs/react' => '^3.0.0'],
        ], JSON_THROW_ON_ERROR));
        $files->put($root.'/resources/css/app.css', "@import 'tailwindcss';\n");
        $files->put($root.'/resources/js/app.js', "// Laravel's existing entrypoint\n");

        expect(runInlayInstall($root, ['--panels' => true, '--media' => true, '--no-npm' => true]))->toBe(0)
            ->and($files->get($root.'/app/Providers/Inlay/AdminPanelProvider.php'))
            ->toContain('namespace App\\Providers\\Inlay;')
            ->toContain("->path('/admin')")
            ->toContain('->resources([UserResource::class])')
            ->toContain('->plugin(MediaManagerPlugin::make())')
            ->toContain("->loginComponent('inlay/auth/login')")
            ->toContain('->accountSettings()')
            ->toContain("return 'admin';")
            ->and($files->get($root.'/config/inlay-panels.php'))
            ->toContain('App\\Providers\\Inlay\\AdminPanelProvider::class');

        expect($files->exists($root.'/app/Inlay/Resources/UserResource.php'))->toBeTrue()
            ->and($files->get($root.'/app/Inlay/Resources/UserResource.php'))
            ->toContain('@param  array<string, mixed>  $data')
            ->toContain('@return array<string, mixed>')
            ->and($files->exists($root.'/app/Validation/UserRules.php'))->toBeTrue()
            ->and($files->exists($root.'/database/migrations/2026_01_01_000000_create_inlay_media_tables.php'))->toBeTrue()
            ->and($files->exists($root.'/database/migrations/2026_08_02_010000_create_inlay_media_collections.php'))->toBeTrue()
            ->and($files->get($root.'/app/Models/User.php'))
            ->toContain('implements PanelAccount')
            ->toContain('use InteractsWithPanelAccount;')
            ->and($files->get($root.'/bootstrap/app.php'))
            ->toContain("route('inlay.admin.login')")
            ->and($files->get($root.'/resources/css/app.css'))
            ->toContain("@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';")
            ->and($files->exists($root.'/resources/js/pages/inlay/auth/login.tsx'))->toBeTrue()
            ->and($files->get($root.'/resources/js/pages/inlay/auth/login.tsx'))
            ->toContain('themeVariables(theme, "light")')
            ->and($files->get($root.'/resources/js/pages/inlay/account-settings.tsx'))
            ->toContain('inlayPanel.theme')
            ->toContain('theme={theme}')
            ->and($files->get($root.'/resources/js/pages/users/index.tsx'))
            ->toContain('inlayPanel.theme')
            ->toContain('theme={theme}')
            ->and($files->get($root.'/resources/js/pages/users/form.tsx'))
            ->toContain('inlayPanel.theme')
            ->toContain('theme={theme}')
            ->and($files->get($root.'/resources/js/pages/inlay/dashboard.tsx'))
            ->toContain('/admin/media')
            ->not->toContain('\\n  ["Media"')
            ->and($files->exists($root.'/resources/js/app.tsx'))->toBeTrue()
            ->and($files->get($root.'/resources/js/app.tsx'))
            ->toContain('createInertiaApp')
            ->toContain('resolve:')
            ->toContain('import.meta.glob')
            ->and($files->exists($root.'/resources/views/app.blade.php'))->toBeTrue()
            ->and($files->get($root.'/resources/views/app.blade.php'))
            ->toContain("@vite(['resources/css/app.css', 'resources/js/app.tsx'])")
            ->and($files->exists($root.'/app/Http/Middleware/HandleInertiaRequests.php'))->toBeTrue()
            ->and($files->get($root.'/app/Http/Middleware/HandleInertiaRequests.php'))
            ->toContain('extends Middleware')
            ->toContain("'auth'")
            ->and($files->get($root.'/bootstrap/app.php'))
            ->toContain('HandleInertiaRequests::class')
            ->toContain('->web(append:')
            ->and($files->exists($root.'/vite.config.js'))->toBeTrue()
            ->and($files->get($root.'/vite.config.js'))
            ->toContain("import inertia from '@inertiajs/vite';")
            ->toContain("import react from '@vitejs/plugin-react';")
            ->toContain("'resources/js/app.js'")
            ->toContain("'resources/js/app.tsx'")
            ->and($files->exists($root.'/resources/js/pages/users/index.tsx'))->toBeTrue();

        $package = json_decode($files->get($root.'/package.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($package['dependencies'])
            ->toHaveKey('@inertiajs/react', '^3.0.0')
            ->toHaveKey('@inertiajs/vite', '^3.0.0')
            ->toHaveKey('@inlayphp/panels-react', '^0.3.0')
            ->toHaveKey('@inlayphp/media-manager-react', '^0.3.0')
            ->toHaveKey('@inlayphp/resources-react', '^0.3.0')
            ->toHaveKey('react', '^19.0.0')
            ->toHaveKey('react-dom', '^19.0.0');
        expect($package['devDependencies'])
            ->toHaveKey('@vitejs/plugin-react', '^6.0.0')
            ->toHaveKey('@types/react', '^19.0.0')
            ->toHaveKey('@types/react-dom', '^19.0.0')
            ->toHaveKey('typescript', '^5.7.0');

        foreach ($files->allFiles($root.'/app') as $generated) {
            if ($generated->getExtension() !== 'php') {
                continue;
            }

            $lint = new Process([PHP_BINARY, '-l', $generated->getPathname()]);
            $lint->run();
            expect($lint->isSuccessful())->toBeTrue($lint->getErrorOutput().$lint->getOutput());
        }

        $files->append($root.'/app/Inlay/Resources/UserResource.php', "\n// application customization\n");
        expect(runInlayInstall($root, ['--no-npm' => true]))->toBe(0)
            ->and($files->get($root.'/app/Inlay/Resources/UserResource.php'))
            ->toContain('// application customization');
        expect(runInlayInstall($root, ['--force' => true, '--renderer' => 'vue', '--no-npm' => true]))->toBe(0)
            ->and($files->get($root.'/app/Providers/Inlay/AdminPanelProvider.php'))
            ->toContain("->renderComponent('InlayPanelLayout')");
    } finally {
        $files->deleteDirectory($root);
    }
});

it('completes the current Laravel React starter bootstrap instead of preserving a blank client', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-install-react-starter-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app/Models');
        $files->ensureDirectoryExists($root.'/bootstrap');
        $files->ensureDirectoryExists($root.'/resources/css');
        $files->ensureDirectoryExists($root.'/resources/js');
        $files->ensureDirectoryExists($root.'/resources/views');
        $files->put($root.'/app/Models/User.php', "<?php\nnamespace App\\Models;\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;\nclass User extends Authenticatable {}\n");
        $files->put($root.'/bootstrap/app.php', "<?php\nuse Illuminate\\Foundation\\Configuration\\Middleware;\nreturn Application::configure()->withMiddleware(function (Middleware \$middleware): void {});\n");
        $files->put($root.'/package.json', json_encode(['private' => true, 'dependencies' => ['@inertiajs/react' => '^3.0.0']], JSON_THROW_ON_ERROR));
        $files->put($root.'/resources/css/app.css', "@import 'tailwindcss';\n");
        $files->put($root.'/resources/views/app.blade.php', <<<'BLADE'
@vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
BLADE);
        $files->put($root.'/resources/js/app.tsx', <<<'TSX'
import { createInertiaApp } from '@inertiajs/react';

createInertiaApp({
    title: (title) => title ?? 'Laravel',
});
TSX);

        expect(runInlayInstall($root, ['--no-npm' => true]))->toBe(0)
            ->and($files->get($root.'/resources/js/app.tsx'))
            ->toContain('resolve:')
            ->toContain('import.meta.glob')
            ->not->toContain("title: (title) => title ?? 'Laravel'")
            ->and($files->get($root.'/resources/views/app.blade.php'))
            ->toContain("@vite(['resources/css/app.css', 'resources/js/app.tsx'])")
            ->not->toContain('resources/js/pages/{$page[\'component\']}.tsx');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('fails before writing panel files when the React frontend is incomplete', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-install-preflight-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app');
        $files->put($root.'/package.json', json_encode(['private' => true], JSON_THROW_ON_ERROR));

        expect(runInlayInstall($root, ['--no-npm' => true]))->toBe(1)
            ->and($files->exists($root.'/app/Providers/Inlay/AdminPanelProvider.php'))->toBeFalse()
            ->and($files->exists($root.'/config/inlay-panels.php'))->toBeFalse();
    } finally {
        $files->deleteDirectory($root);
    }
});

it('keeps media opt-in for the default panel preset', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-install-no-media-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app/Models');
        $files->ensureDirectoryExists($root.'/bootstrap');
        $files->ensureDirectoryExists($root.'/resources/css');
        $files->ensureDirectoryExists($root.'/resources/js');
        $files->put($root.'/app/Models/User.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
}
PHP);
        $files->put($root.'/bootstrap/app.php', <<<'PHP'
<?php

use Illuminate\Foundation\Configuration\Middleware;

return Application::configure()
    ->withMiddleware(function (Middleware $middleware): void {
    });
PHP);
        $files->put($root.'/package.json', json_encode([
            'private' => true,
            'dependencies' => [],
        ], JSON_THROW_ON_ERROR));
        $files->put($root.'/resources/css/app.css', "@import 'tailwindcss';\n");

        expect(runInlayInstall($root, ['--no-npm' => true]))->toBe(0)
            ->and($files->get($root.'/app/Providers/Inlay/AdminPanelProvider.php'))
            ->not->toContain('MediaManagerPlugin')
            ->and($files->get($root.'/resources/js/pages/inlay/dashboard.tsx'))
            ->not->toContain('/admin/media')
            ->and($files->exists($root.'/database/migrations/2026_01_01_000000_create_inlay_media_tables.php'))->toBeFalse()
            ->and($files->exists($root.'/resources/js/pages/inlay-media-manager/index.tsx'))->toBeFalse();

        $package = json_decode($files->get($root.'/package.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($package['dependencies'])->not->toHaveKey('@inlayphp/media-manager-react');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('scaffolds the Vue preset while preserving an existing starter package', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-install-vue-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app/Models');
        $files->ensureDirectoryExists($root.'/bootstrap');
        $files->ensureDirectoryExists($root.'/resources/css');
        $files->ensureDirectoryExists($root.'/resources/js');
        $files->put($root.'/app/Models/User.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
}
PHP);
        $files->put($root.'/bootstrap/app.php', <<<'PHP'
<?php

use Illuminate\Foundation\Configuration\Middleware;

return Application::configure()
    ->withMiddleware(function (Middleware $middleware): void {
    });
PHP);
        $files->put($root.'/package.json', json_encode([
            'private' => true,
            'dependencies' => [
                '@inertiajs/vue3' => '^3.0.0',
                'starter-only-package' => '^1.2.3',
                'vue' => '^3.5.0',
            ],
            'devDependencies' => ['starter-tooling' => '^4.5.6'],
        ], JSON_THROW_ON_ERROR));
        $files->put($root.'/resources/css/app.css', "@import 'tailwindcss';\n");
        $files->put($root.'/vite.config.ts', <<<'TS'
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css'], refresh: true }),
        tailwindcss(),
    ],
});
TS);

        expect(runInlayInstall($root, ['--renderer' => 'vue', '--media' => true, '--no-npm' => true]))->toBe(0)
            ->and($files->exists($root.'/resources/js/app.ts'))->toBeTrue()
            ->and($files->get($root.'/resources/js/app.ts'))->toContain("createInertiaApp")
            ->and($files->exists($root.'/resources/views/app.blade.php'))->toBeTrue()
            ->and($files->get($root.'/resources/views/app.blade.php'))->toContain("resources/js/app.ts")
            ->and($files->exists($root.'/resources/js/pages/inlay/auth/login.vue'))->toBeTrue()
            ->and($files->exists($root.'/resources/js/pages/inlay-media-manager/index.vue'))->toBeTrue()
            ->and($files->get($root.'/resources/js/pages/inlay/account-settings.vue'))
            ->toContain('page.props.inlayPanel.theme')
            ->toContain(':theme="theme"')
            ->and($files->get($root.'/resources/js/pages/users/index.vue'))
            ->toContain('page.props.inlayPanel.theme')
            ->toContain(':theme="theme"')
            ->and($files->get($root.'/resources/js/pages/users/form.vue'))
            ->toContain('page.props.inlayPanel.theme')
            ->toContain(':theme="theme"')
            ->and($files->get($root.'/vite.config.ts'))
            ->toContain("import inertia from '@inertiajs/vite';")
            ->toContain("import vue from '@vitejs/plugin-vue';")
            ->toContain("inertia()")
            ->toContain("vue()")
            ->toContain("resources/js/app.ts");

        $package = json_decode($files->get($root.'/package.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($package['dependencies'])
            ->toHaveKey('starter-only-package', '^1.2.3')
            ->toHaveKey('@inlayphp/panels-vue', '^0.3.0')
            ->toHaveKey('@inlayphp/media-manager-vue', '^0.3.0')
            ->toHaveKey('@inlayphp/resources-vue', '^0.3.0')
            ->toHaveKey('@inlayphp/widgets-vue', '^0.3.0');
        expect($package['devDependencies'])
            ->toHaveKey('starter-tooling', '^4.5.6')
            ->toHaveKey('@vitejs/plugin-vue', '^6.0.0')
            ->toHaveKey('vue-tsc', '^2.2.0');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('diagnoses the panel installation and compiled Inlay CSS', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-doctor-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app/Providers/Inlay');
        $files->ensureDirectoryExists($root.'/app/Models');
        $files->ensureDirectoryExists($root.'/app/Inlay/Resources');
        $files->ensureDirectoryExists($root.'/config');
        $files->ensureDirectoryExists($root.'/resources/css');
        $files->ensureDirectoryExists($root.'/database/migrations');
        $files->ensureDirectoryExists($root.'/public/build/assets');
        $files->put($root.'/config/inlay-panels.php', "<?php return ['providers' => [App\\Providers\\Inlay\\AdminPanelProvider::class]];\n");
        $files->put($root.'/app/Providers/Inlay/AdminPanelProvider.php', <<<'PHP'
<?php
final class AdminPanelProvider extends PanelProvider
{
    // UserResource::class MediaManagerPlugin
}
PHP);
        $files->put($root.'/app/Models/User.php', '<?php class User implements PanelAccount {}');
        $files->put($root.'/app/Inlay/Resources/UserResource.php', '<?php');
        $files->put($root.'/database/migrations/2026_08_12_103953_create_inlay_media_tables.php', '<?php');
        $files->put($root.'/database/migrations/2026_08_12_103954_create_inlay_media_collections.php', '<?php');
        $files->put($root.'/package.json', json_encode([
            'dependencies' => ['@inlayphp/panels-react' => '^0.3.0'],
        ], JSON_THROW_ON_ERROR));
        $files->put($root.'/resources/css/app.css', "@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';\n");
        $files->put($root.'/public/build/manifest.json', json_encode([
            'resources/css/app.css' => ['file' => 'assets/app.css'],
        ], JSON_THROW_ON_ERROR));
        $files->put($root.'/public/build/assets/app.css', '.text-\\(--inlay-text\\){}.ring-\\(--inlay-border\\){}.min-h-\\(--inlay-control-height\\){}');

        [$status, $output] = runInlayDoctor($root, ['--production' => true]);
        expect($status)->toBe(0)
            ->and($output)->toContain('Inlay is ready.')
            ->toContain('Compiled production assets contain Inlay utilities');

        $files->put($root.'/public/build/manifest.json', json_encode([
            'resources/js/app.tsx' => [
                'file' => 'assets/app.js',
                'css' => ['assets/app.css'],
            ],
        ], JSON_THROW_ON_ERROR));
        [$scriptEntryStatus] = runInlayDoctor($root, ['--production' => true]);
        expect($scriptEntryStatus)->toBe(0);

        $files->put($root.'/public/build/assets/app.css', '/* missing package utilities */');
        [$brokenStatus, $brokenOutput] = runInlayDoctor($root, ['--production' => true]);
        expect($brokenStatus)->toBe(1)
            ->and($brokenOutput)->toContain('Inlay doctor found 1 problem(s).');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('merges an existing User interface without corrupting the class declaration', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-install-interface-'.bin2hex(random_bytes(6));

    try {
        $files->ensureDirectoryExists($root.'/app/Models');
        $files->ensureDirectoryExists($root.'/bootstrap');
        $files->ensureDirectoryExists($root.'/resources/css');
        $files->ensureDirectoryExists($root.'/resources/js');
        $files->put($root.'/app/Models/User.php', <<<'PHP'
<?php

namespace App\Models;

use IlluminateFoundationAuthUser as Authenticatable;

class User extends Authenticatable implements ExistingUserContract
{
}
PHP);
        $files->put($root.'/bootstrap/app.php', <<<'PHP'
<?php

use IlluminateFoundation\Configuration\Middleware;

return Application::configure()
    ->withMiddleware(function (Middleware $middleware): void {
    });
PHP);
        $files->put($root.'/package.json', json_encode(['private' => true], JSON_THROW_ON_ERROR));
        $files->put($root.'/resources/css/app.css', "@import 'tailwindcss';\n");
        $files->put($root.'/resources/js/app.js', "// Laravel's existing entrypoint\n");

        expect(runInlayInstall($root, ['--panels' => true, '--no-npm' => true]))->toBe(0)
            ->and($files->get($root.'/app/Models/User.php'))
            ->toContain('class User extends Authenticatable implements ExistingUserContract, PanelAccount')
            ->not->toContain('Authenticatableimplements');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('creates the first panel user without shipping default credentials', function (): void {
    $app = new Application(sys_get_temp_dir().'/inlay-user-'.bin2hex(random_bytes(6)));
    $app->instance('config', new Repository([
        'auth.providers.users.model' => InstallerCommandUser::class,
    ]));

    $database = new Capsule($app);
    $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $database->setAsGlobal();
    $database->bootEloquent();
    $database->schema()->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
    });

    $validator = new ValidatorFactory(new Translator(new ArrayLoader, 'en'), $app);
    $validator->setPresenceVerifier(new DatabasePresenceVerifier($database->getDatabaseManager()));
    $hasher = new BcryptHasher(['rounds' => 4]);
    $command = new MakeUserCommand($validator, $hasher);
    $command->setLaravel($app);
    $console = new ConsoleApplication;
    $console->setAutoExit(false);
    ConsoleCommandRegistrar::add($console, $command);

    $status = $console->run(new ArrayInput([
        'command' => 'inlay:make-user',
        '--name' => 'Panel Owner',
        '--email' => 'owner@example.com',
        '--password' => 'correct-horse-battery-staple',
    ]), new BufferedOutput);

    $user = InstallerCommandUser::query()->sole();
    expect($status)->toBe(0)
        ->and($user->name)->toBe('Panel Owner')
        ->and($user->email)->toBe('owner@example.com')
        ->and($hasher->check('correct-horse-battery-staple', $user->password))->toBeTrue();
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
            '--renderer' => 'none',
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
