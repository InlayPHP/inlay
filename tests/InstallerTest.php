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
use Inlay\Installer\InstallCommand;
use Inlay\Installer\MakeUserCommand;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

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
    $console->addCommand($command);

    return $console->run(new ArrayInput(['command' => 'inlay:install', ...$arguments]), new BufferedOutput);
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

        expect(runInlayInstall($root, ['--panels' => true, '--no-npm' => true]))->toBe(0)
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
            ->and($files->exists($root.'/app/Validation/UserRules.php'))->toBeTrue()
            ->and($files->exists($root.'/database/migrations/2026_01_01_000000_create_inlay_media_tables.php'))->toBeTrue()
            ->and($files->exists($root.'/database/migrations/2026_08_02_010000_create_inlay_media_collections.php'))->toBeTrue()
            ->and($files->get($root.'/app/Models/User.php'))
            ->toContain('implements PanelAccount')
            ->toContain('use InteractsWithPanelAccount;')
            ->and($files->get($root.'/bootstrap/app.php'))
            ->toContain("route('inlay.admin.login')")
            ->and($files->get($root.'/resources/css/app.css'))
            ->toContain("@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx}';")
            ->and($files->exists($root.'/resources/js/pages/inlay/auth/login.tsx'))->toBeTrue()
            ->and($files->exists($root.'/resources/js/pages/users/index.tsx'))->toBeTrue();

        $package = json_decode($files->get($root.'/package.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($package['dependencies'])
            ->toHaveKey('@inlayphp/panels-react', '^0.3.0')
            ->toHaveKey('@inlayphp/media-manager-react', '^0.3.0')
            ->toHaveKey('@inlayphp/resources-react', '^0.3.0');

        foreach ($files->allFiles($root.'/app') as $generated) {
            if ($generated->getExtension() !== 'php') {
                continue;
            }

            $lint = new Process([PHP_BINARY, '-l', $generated->getPathname()]);
            $lint->run();
            expect($lint->isSuccessful())->toBeTrue($lint->getErrorOutput().$lint->getOutput());
        }

        expect(runInlayInstall($root, ['--no-npm' => true]))->toBe(1);
        expect(runInlayInstall($root, ['--force' => true, '--renderer' => 'vue', '--no-npm' => true]))->toBe(0)
            ->and($files->get($root.'/app/Providers/Inlay/AdminPanelProvider.php'))
            ->toContain("->renderComponent('InlayPanelLayout')");
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
    $console->addCommand($command);

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
