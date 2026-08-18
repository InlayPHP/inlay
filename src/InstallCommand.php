<?php

declare(strict_types=1);

namespace Inlay\Installer;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Inlay\Media\MediaServiceProvider;
use Symfony\Component\Process\Process;

final class InstallCommand extends Command
{
    protected $signature = 'inlay:install
        {--panels : Install the complete administration panel preset (the default)}
        {--panel=admin : Panel identifier and URL segment}
        {--renderer=react : Frontend renderer hint: react, vue, or none}
        {--tenant-model= : Fully-qualified Eloquent tenant model (for example App\\Models\\Team)}
        {--tenant-parameter=tenant : Tenant route parameter name}
        {--tenant-route-key= : Tenant route key (defaults to the model route key)}
        {--path=app/Providers/Inlay : Relative directory for the generated panel provider}
        {--force : Overwrite the generated provider file}
        {--media : Install and register the bundled Media Manager (opt-in)}
        {--without-media : Legacy alias; media is disabled by default}
        {--without-users : Do not generate the default User resource}
        {--no-frontend : Do not scaffold the official renderer entry points}
        {--no-npm : Update frontend files without running the JavaScript package manager}
        {--no-panel : Only create or update the panel configuration}';

    protected $description = 'Install a complete Inlay administration panel';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $panel = $this->panelId((string) $this->option('panel'));
        $renderer = strtolower(trim((string) $this->option('renderer')));
        $tenantModel = $this->tenantModel((string) ($this->option('tenant-model') ?? ''));
        $tenantParameter = $this->identifier((string) $this->option('tenant-parameter'));
        $tenantRouteKey = $this->nullableIdentifier((string) ($this->option('tenant-route-key') ?? ''));
        $directory = $this->relativeDirectory((string) $this->option('path'));
        if ((bool) $this->option('media') && (bool) $this->option('without-media')) {
            $this->components->error('Use either --media or --without-media, not both. Media is disabled by default.');

            return self::FAILURE;
        }

        $installMedia = (bool) $this->option('media');
        $installUsers = ! (bool) $this->option('without-users');
        $createPanel = ! (bool) $this->option('no-panel');

        if ($panel === null || ! in_array($renderer, ['react', 'vue', 'none'], true) || $tenantParameter === null || $tenantRouteKey === false || $directory === null) {
            $this->components->error('The panel, renderer, tenant, and output path must use safe values.');

            return self::FAILURE;
        }

        if ($this->option('tenant-model') !== null && $tenantModel === null) {
            $this->components->error('The tenant model must be a fully-qualified Eloquent model class.');

            return self::FAILURE;
        }

        if ($createPanel && in_array($renderer, ['react', 'vue'], true) && ! $this->option('no-frontend') && ! $this->frontendPreflight($renderer)) {
            return self::FAILURE;
        }

        $appNamespace = rtrim((string) $this->laravel->getNamespace(), '\\');
        $class = Str::studly(str_replace('-', ' ', $panel)).'PanelProvider';
        $namespace = $this->providerNamespace($appNamespace, $directory);
        $fqcn = $namespace.'\\'.$class;
        $providerPath = $this->laravel->basePath($directory).DIRECTORY_SEPARATOR.$class.'.php';

        if ($createPanel) {
            if ($this->files->exists($providerPath) && ! $this->option('force')) {
                $this->components->info("Panel provider already exists; keeping {$fqcn}");
            } else {
                $this->files->ensureDirectoryExists(dirname($providerPath));
                $this->files->put($providerPath, $this->providerSource(
                    $namespace,
                    $class,
                    $panel,
                    $tenantModel,
                    $tenantParameter,
                    $tenantRouteKey ?: null,
                    $installUsers,
                    $installMedia,
                ));
                $this->components->info("Created {$fqcn}");
            }

            if ($installUsers && ! $this->scaffoldUserResource($appNamespace, $panel)) {
                return self::FAILURE;
            }

            if ($installMedia && ! $this->publishMediaMigrations()) {
                return self::FAILURE;
            }

            $this->configureUserModel($appNamespace);
            $this->configureGuestRedirect($panel);

            if ($renderer !== 'none' && ! $this->option('no-frontend')) {
                $frontendReady = $this->scaffoldFrontend($renderer, $panel, $installMedia);
                if (! $frontendReady && in_array($renderer, ['react', 'vue'], true)) {
                    return self::FAILURE;
                }

                if ($frontendReady && in_array($renderer, ['react', 'vue'], true) && ! $this->option('no-npm') && ! $this->installFrontendDependencies()) {
                    return self::FAILURE;
                }
            }
        }

        $configPath = $this->laravel->configPath('inlay-panels.php');
        $configResult = $this->registerProvider($configPath, $fqcn);
        if ($configResult === false) {
            $this->components->error("Could not safely update {$configPath}. Add {$fqcn}::class to the providers array.");

            return self::FAILURE;
        }

        if ($configResult === 'created') {
            $this->components->info('Created config/inlay-panels.php');
        } elseif ($configResult === 'updated') {
            $this->components->info('Registered the panel provider in config/inlay-panels.php');
        }

        $this->newLine();
        $this->components->info("Inlay is installed. Open /{$panel} after running your migrations and frontend build.");

        if (! $createPanel) {
            $this->line('Configuration only: no panel preset or frontend files were generated.');
        } elseif ($renderer === 'react') {
            $this->line('Frontend: the official React packages, page wrappers, and Tailwind source scanning are ready.');
            $this->line('Next: php artisan migrate && php artisan inlay:make-user && '.$this->frontendBuildCommand());
        } elseif ($renderer === 'vue') {
            $this->line('Frontend: the official Vue packages, page wrappers, and Tailwind source scanning are ready.');
            $this->line('Next: php artisan migrate && php artisan inlay:make-user && '.$this->frontendBuildCommand());
        } else {
            $this->line('Next: install one official renderer or provide your own renderer for inlay.panels.v1.');
        }

        if ($createPanel && $installUsers) {
            $this->line("Users: the generated User resource is available at /{$panel}/users.");
            $this->line('First login: run php artisan inlay:make-user after migrating a new application.');
        }

        if ($createPanel && $installMedia) {
            $this->line("Media: run php artisan migrate, then open /{$panel}/media.");
        }

        if ($tenantModel !== null) {
            $this->line("Tenant-aware routes are enabled under the '{$tenantParameter}' parameter.");
        }

        return self::SUCCESS;
    }

    private function panelId(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^[a-z][a-z0-9-]*$/', $value) === 1 ? $value : null;
    }

    private function tenantModel(string $value): ?string
    {
        $value = ltrim(trim($value), '\\');

        if ($value === '') {
            return null;
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $value) === 1
            ? $value
            : null;
    }

    private function identifier(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^[a-z][a-z0-9_-]*$/', $value) === 1 ? $value : null;
    }

    private function nullableIdentifier(string $value): string|false|null
    {
        $value = trim($value);

        return $value === '' ? null : ($this->identifier($value) ?? false);
    }

    private function relativeDirectory(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\/]/', $path) === 1) {
            return null;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9_-]+$/', $segment) !== 1) {
                return null;
            }
        }

        return implode(DIRECTORY_SEPARATOR, $segments);
    }

    private function providerNamespace(string $appNamespace, string $directory): string
    {
        $segments = explode('/', str_replace(DIRECTORY_SEPARATOR, '/', $directory));
        if ($segments[0] === 'app') {
            array_shift($segments);
        }

        $namespaceSegments = array_values(array_filter(array_map(
            static fn (string $segment): string => Str::studly($segment),
            $segments,
        ), static fn (string $segment): bool => $segment !== ''));

        return $appNamespace.($namespaceSegments === [] ? '' : '\\'.implode('\\', $namespaceSegments));
    }

    /** @return 'created'|'updated'|true|false */
    private function registerProvider(string $path, string $fqcn): string|bool
    {
        $reference = $fqcn.'::class';
        if (! $this->files->exists($path)) {
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, "<?php\n\nreturn [\n    'providers' => [\n        {$reference},\n    ],\n];\n");

            return 'created';
        }

        $contents = $this->files->get($path);
        if (str_contains($contents, $reference)) {
            return true;
        }

        $pattern = "/('providers'\\s*=>\\s*\\[)(.*?)(\\])/s";
        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }

        $insertAt = $matches[3][1];
        $replacement = "\n        {$reference},\n    ";
        $contents = substr($contents, 0, $insertAt).$replacement.substr($contents, $insertAt);
        $this->files->put($path, $contents);

        return 'updated';
    }

    private function providerSource(
        string $namespace,
        string $class,
        string $panel,
        ?string $tenantModel,
        string $tenantParameter,
        ?string $tenantRouteKey,
        bool $installUsers,
        bool $installMedia,
    ): string {
        $path = '/'.$panel;
        $tenantImport = $tenantModel === null ? '' : "use {$tenantModel};\n";
        $tenantClass = $tenantModel === null ? '' : Str::afterLast($tenantModel, '\\');
        $tenant = $tenantModel === null
            ? ''
            : "            ->tenant({$tenantClass}::class, parameter: '{$tenantParameter}'".($tenantRouteKey === null ? '' : ", routeKey: '{$tenantRouteKey}'").")\n";
        $appNamespace = rtrim((string) $this->laravel->getNamespace(), '\\');
        $userImport = $installUsers ? "use {$appNamespace}\\Inlay\\Resources\\UserResource;\n" : '';
        $mediaImport = $installMedia
            ? "use Illuminate\\Support\\Facades\\Gate;\nuse Inlay\\MediaManager\\MediaManagerPlugin;\n"
            : '';
        $resources = $installUsers ? "            ->resources([UserResource::class])\n" : '';
        $media = $installMedia ? "            ->plugin(MediaManagerPlugin::make())\n" : '';
        $boot = $installMedia
            ? <<<'PHP'

    public function boot(): void
    {
        foreach (MediaManagerPlugin::abilityDefinitions() as $ability) {
            if (! Gate::has($ability->name())) {
                Gate::define($ability->name(), static fn (mixed $user): bool => $user !== null);
            }
        }
    }
PHP
            : '';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$userImport}{$mediaImport}use Inlay\\Panel;
use Inlay\\PanelProvider;
use Inlay\\Theme\\Theme;
{$tenantImport}

final class {$class} extends PanelProvider
{
    public function panel(Panel \$panel): Panel
    {
        return \$panel
            ->path('{$path}')
{$tenant}            ->brandName('Inlay')
            ->theme(Theme::default())
            ->sidebarNavigation()
            ->collapsible()
            ->breadcrumbs()
            ->topbar()
            ->middleware(['web'])
            ->authMiddleware(['auth'])
            ->loginComponent('inlay/auth/login')
            ->dashboardComponent('inlay/dashboard')
            ->accountSettings()
{$resources}{$media}            ->globalSearch()
            ->spa()
            ->renderComponent('InlayPanelLayout');
    }
{$boot}

    protected function panelId(): string
    {
        return '{$panel}';
    }

    protected function isDefaultPanel(): bool
    {
        return true;
    }
}
PHP;
    }

    private function scaffoldUserResource(string $appNamespace, string $panel): bool
    {
        $namespace = rtrim($appNamespace, '\\');
        $files = $this->userResourceFiles($namespace, $panel);

        foreach ($files as $path => $source) {
            if ($this->files->exists($path) && ! $this->option('force')) {
                $this->components->info('User resource file already exists; keeping '.Str::after($path, $this->laravel->basePath().DIRECTORY_SEPARATOR));

                continue;
            }

            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $source);
            $this->components->info('Created '.Str::after($path, $this->laravel->basePath().DIRECTORY_SEPARATOR));
        }

        return true;
    }

    /** @return array<string, string> */
    private function userResourceFiles(string $namespace, string $panel): array
    {
        return [
            $this->laravel->basePath('app/Inlay/Resources/UserResource.php') => $this->userResourceSource($namespace, $panel),
            $this->laravel->basePath('app/Inlay/Resources/ListUsers.php') => $this->userPageSource($namespace, 'ListUsers', 'ListRecords', 'users/index'),
            $this->laravel->basePath('app/Inlay/Resources/CreateUser.php') => $this->userPageSource($namespace, 'CreateUser', 'CreateRecord', 'users/form'),
            $this->laravel->basePath('app/Inlay/Resources/EditUser.php') => $this->userPageSource($namespace, 'EditUser', 'EditRecord', 'users/form'),
            $this->laravel->basePath('app/Validation/UserRules.php') => $this->userRulesSource($namespace),
        ];
    }

    private function publishMediaMigrations(): bool
    {
        $packageRoot = dirname((new \ReflectionClass(MediaServiceProvider::class))->getFileName(), 2);
        $sources = [
            $packageRoot.'/database/migrations/2026_01_01_000000_create_inlay_media_tables.php',
            $packageRoot.'/database/migrations/2026_08_02_010000_create_inlay_media_collections.php',
        ];

        foreach ($sources as $source) {
            if (! $this->files->exists($source)) {
                $this->components->error("Bundled media migration is missing: {$source}");

                return false;
            }

            $destination = $this->laravel->databasePath('migrations/'.basename($source));
            if ($this->files->exists($destination) && ! $this->option('force')) {
                $this->components->info('Media migration already exists: '.basename($destination));

                continue;
            }

            $this->files->ensureDirectoryExists(dirname($destination));
            $this->files->copy($source, $destination);
            $this->components->info('Published '.basename($destination));
        }

        return true;
    }

    private function configureUserModel(string $appNamespace): void
    {
        $path = $this->laravel->basePath('app/Models/User.php');
        if (! $this->files->exists($path)) {
            $this->components->warn('App\\Models\\User was not found. Implement Inlay\\Contracts\\PanelAccount on your authenticatable model to enable account settings.');

            return;
        }

        $contents = $this->files->get($path);
        if (str_contains($contents, 'PanelAccount')) {
            return;
        }

        $modelNamespace = rtrim($appNamespace, '\\').'\\Models';
        $contents = preg_replace(
            '/(namespace\s+'.preg_quote($modelNamespace, '/').';\s*)/',
            "$1\nuse Inlay\\Concerns\\InteractsWithPanelAccount;\nuse Inlay\\Contracts\\PanelAccount;\n",
            $contents,
            1,
        ) ?? $contents;
        $contents = preg_replace_callback(
            '/class\s+User\s+extends\s+Authenticatable(?<interfaces>\s+implements\s+[^\{]+)?\s*\{/',
            static function (array $matches): string {
                $interfaces = trim((string) ($matches['interfaces'] ?? ''));
                $implements = $interfaces === '' ? 'implements PanelAccount' : $interfaces.', PanelAccount';

                return "class User extends Authenticatable {$implements}\n{\n    use InteractsWithPanelAccount;";
            },
            $contents,
            1,
            $count,
        ) ?? $contents;

        if ($count !== 1) {
            $this->components->warn('Could not safely update App\\Models\\User. Implement PanelAccount and use InteractsWithPanelAccount manually.');

            return;
        }

        $this->files->put($path, $contents);
        $this->components->info('Enabled panel account settings on App\\Models\\User');
    }

    private function configureGuestRedirect(string $panel): void
    {
        $path = $this->laravel->basePath('bootstrap/app.php');
        if (! $this->files->exists($path)) {
            return;
        }

        $contents = $this->files->get($path);
        if (str_contains($contents, 'redirectGuestsTo(')) {
            return;
        }

        $pattern = '/(->withMiddleware\(function\s*\(Middleware\s+\$middleware\)(?::\s*void)?\s*\{)/';
        $replacement = "$1\n        \$middleware->redirectGuestsTo(fn (): string => route('inlay.{$panel}.login'));";
        $updated = preg_replace($pattern, $replacement, $contents, 1, $count);

        if ($updated === null || $count !== 1) {
            $this->components->warn("Set Laravel's guest redirect to route('inlay.{$panel}.login') in bootstrap/app.php.");

            return;
        }

        $this->files->put($path, $updated);
        $this->components->info('Configured unauthenticated requests to use the panel login');
    }

    private function scaffoldFrontend(string $renderer, string $panel, bool $installMedia): bool
    {
        $packagePath = $this->laravel->basePath('package.json');
        if (! $this->files->exists($packagePath)) {
            $this->components->warn("package.json was not found. Install the {$renderer} adapter after adding Inertia to the application.");

            return false;
        }

        if (! $this->updateFrontendDependencies($packagePath, $renderer, $installMedia)) {
            return false;
        }

        $files = $renderer === 'react'
            ? $this->reactFrontendFiles($panel, $installMedia)
            : $this->vueFrontendFiles($panel, $installMedia);

        foreach ($files as $relative => $source) {
            $path = $this->laravel->basePath($relative);
            if ($this->files->exists($path) && ! $this->option('force')) {
                $this->components->warn("Frontend file already exists and was not replaced: {$relative}");

                continue;
            }

            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $source);
            $this->components->info("Created {$relative}");
        }

        if ($renderer === 'react' && ! $this->scaffoldReactApplication()) {
            return false;
        }

        if ($renderer === 'vue' && ! $this->scaffoldVueApplication()) {
            return false;
        }

        return $this->configureTailwindSources();
    }

    /**
     * A plain Laravel application has no Inertia entrypoint. Generate the
     * smallest React/Inertia bootstrap when the application has not already
     * chosen one, while preserving an existing application-owned entrypoint.
     */
    private function scaffoldReactApplication(): bool
    {
        $entryPath = $this->laravel->basePath('resources/js/app.tsx');
        $viewPath = $this->laravel->resourcePath('views/app.blade.php');
        $entryExists = $this->files->exists($entryPath);
        $entryContents = $entryExists ? $this->files->get($entryPath) : '';
        $hasResolver = preg_match('/\bresolve\s*:/', $entryContents) === 1;

        // Laravel's current React starter ships an app.tsx that configures
        // Inertia's title/progress only. It is not a usable client bootstrap
        // until a page resolver is supplied, so replace that known-incomplete
        // entrypoint while preserving application-owned resolvers.
        if (! $entryExists || $this->option('force') || ! $hasResolver) {
            $this->files->ensureDirectoryExists(dirname($entryPath));
            $this->files->put($entryPath, $this->reactApplicationSource());
            $this->components->info($entryExists ? 'Completed the Inertia React entrypoint' : 'Created resources/js/app.tsx');
        }

        if (! $this->files->exists($viewPath) || $this->option('force')) {
            $this->files->ensureDirectoryExists(dirname($viewPath));
            $this->files->put($viewPath, $this->reactApplicationViewSource());
            $this->components->info('Created resources/views/app.blade.php');
        }
        $this->normalizeInertiaRootView($viewPath, 'react');

        $appNamespace = rtrim((string) $this->laravel->getNamespace(), '\\');

        if (! $this->scaffoldInertiaMiddleware($appNamespace)) {
            return false;
        }

        return $this->configureReactVite();
    }

    /**
     * A plain Laravel application has no Inertia entrypoint. Generate the
     * smallest Vue/Inertia bootstrap when the application has not already
     * chosen one, while preserving an existing application-owned entrypoint.
     */
    private function scaffoldVueApplication(): bool
    {
        $entryPath = $this->laravel->basePath('resources/js/app.ts');
        $viewPath = $this->laravel->resourcePath('views/app.blade.php');
        $entryExists = $this->files->exists($entryPath);
        $entryContents = $entryExists ? $this->files->get($entryPath) : '';
        $hasResolver = preg_match('/\bresolve\s*:/', $entryContents) === 1;

        if (! $entryExists || $this->option('force') || ! $hasResolver) {
            $this->files->ensureDirectoryExists(dirname($entryPath));
            $this->files->put($entryPath, $this->vueApplicationSource());
            $this->components->info($entryExists ? 'Completed the Inertia Vue entrypoint' : 'Created resources/js/app.ts');
        }

        if (! $this->files->exists($viewPath) || $this->option('force')) {
            $this->files->ensureDirectoryExists(dirname($viewPath));
            $this->files->put($viewPath, $this->vueApplicationViewSource());
            $this->components->info('Created resources/views/app.blade.php');
        }
        $this->normalizeInertiaRootView($viewPath, 'vue');

        $appNamespace = rtrim((string) $this->laravel->getNamespace(), '\\');

        if (! $this->scaffoldInertiaMiddleware($appNamespace)) {
            return false;
        }

        return $this->configureVueVite();
    }

    private function scaffoldInertiaMiddleware(string $appNamespace): bool
    {
        $middlewarePath = $this->laravel->basePath('app/Http/Middleware/HandleInertiaRequests.php');
        if (! $this->files->exists($middlewarePath) || $this->option('force')) {
            $this->files->ensureDirectoryExists(dirname($middlewarePath));
            $this->files->put($middlewarePath, $this->inertiaMiddlewareSource($appNamespace));
            $this->components->info('Created app/Http/Middleware/HandleInertiaRequests.php');
        }

        $bootstrapPath = $this->laravel->basePath('bootstrap/app.php');
        if (! $this->files->exists($bootstrapPath)) {
            $this->components->error('bootstrap/app.php is missing, so Inertia middleware could not be registered.');

            return false;
        }

        $contents = $this->files->get($bootstrapPath);
        $reference = 'HandleInertiaRequests::class';
        $import = "use {$appNamespace}\\Http\\Middleware\\HandleInertiaRequests;";

        if (! str_contains($contents, $import)) {
            $contents = preg_replace('/(<\?php\s*)/', "$1\n{$import}\n", $contents, 1, $count) ?? $contents;
            if ($count !== 1) {
                $this->components->warn('Could not safely import HandleInertiaRequests in bootstrap/app.php.');

                return false;
            }
        }

        if (! str_contains($contents, $reference)) {
            $pattern = '/(->withMiddleware\(function\s*\(Middleware\s+\$middleware\)(?::\s*void)?\s*\{)/';
            $replacement = "$1\n        \$middleware->web(append: [\n            {$reference},\n        ]);";
            $contents = preg_replace($pattern, $replacement, $contents, 1, $count) ?? $contents;
            if ($count !== 1) {
                $this->components->warn('Could not safely register HandleInertiaRequests in bootstrap/app.php.');

                return false;
            }
        }

        $this->files->put($bootstrapPath, $contents);
        $this->components->info('Registered HandleInertiaRequests in the web middleware');

        return true;
    }

    private function inertiaMiddlewareSource(string $appNamespace): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$appNamespace}\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected \$rootView = 'app';

    public function share(Request \$request): array
    {
        return [
            ...parent::share(\$request),
            'auth' => [
                'user' => \$request->user()?->only(['id', 'name', 'email']),
            ],
            'flash' => [
                'success' => fn (): ?string => \$request->session()->get('success'),
                'error' => fn (): ?string => \$request->session()->get('error'),
            ],
        ];
    }
}
PHP;
    }

    private function reactApplicationSource(): string
    {
        return <<<'TSX'
import { createInertiaApp } from "@inertiajs/react";

const pages = import.meta.glob("./pages/**/*.tsx", { eager: true });
const appName = import.meta.env.VITE_APP_NAME || "Laravel";

createInertiaApp({
  resolve: (name) => {
    const page = pages[`./pages/${name}.tsx`] as
      | { default?: unknown }
      | undefined;

    if (!page?.default) {
      throw new Error(`Unknown Inertia page: ${name}`);
    }

    return page;
  },
  title: (title) => (title ? `${title} - ${appName}` : appName),
  progress: {
    color: "#4f46e5",
  },
});
TSX;
    }

    private function reactApplicationViewSource(): string
    {
        return <<<'BLADE'
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
BLADE;
    }

    private function vueApplicationSource(): string
    {
        return <<<'TS'
import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import type { DefineComponent } from 'vue';

const pages = import.meta.glob<{ default: DefineComponent }>('./pages/**/*.vue', { eager: true });
const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    resolve: (name) => {
        const page = pages[`./pages/${name}.vue`] as { default: DefineComponent } | undefined;

        if (! page?.default) {
            throw new Error(`Unknown Inertia page: ${name}`);
        }

        return page.default;
    },
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4f46e5',
    },
    setup: ({ el, App, props, plugin }) => {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
TS;
    }

    /**
     * Laravel's starter views may list the current page as a second Vite
     * entry. That works in dev but is not present in the production manifest
     * when the resolver loads pages through import.meta.glob. Keep one stable
     * application entry so both dev and production use the same contract.
     */
    private function normalizeInertiaRootView(string $path, string $renderer): void
    {
        if (! $this->files->exists($path)) {
            return;
        }

        $contents = $this->files->get($path);
        $extension = $renderer === 'vue' ? 'ts' : 'tsx';
        $pageExtension = $renderer === 'vue' ? 'vue' : 'tsx';
        $legacy = "@vite(['resources/css/app.css', 'resources/js/app.{$extension}', \"resources/js/pages/{\$page['component']}.{$pageExtension}\"])";
        $stable = "@vite(['resources/css/app.css', 'resources/js/app.{$extension}'])";

        if (! str_contains($contents, $legacy)) {
            return;
        }

        $this->files->put($path, str_replace($legacy, $stable, $contents));
        $this->components->info('Normalized the Inertia root view for production builds');
    }

    private function vueApplicationViewSource(): string
    {
        return <<<'BLADE'
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
BLADE;
    }

    private function configureReactVite(): bool
    {
        $configPath = null;
        foreach (['vite.config.js', 'vite.config.mjs', 'vite.config.ts'] as $relative) {
            $candidate = $this->laravel->basePath($relative);
            if ($this->files->exists($candidate)) {
                $configPath = $candidate;

                break;
            }
        }

        if ($configPath === null) {
            $configPath = $this->laravel->basePath('vite.config.js');
            $this->files->put($configPath, $this->reactViteConfigSource(
                $this->files->exists($this->laravel->basePath('resources/js/app.js')),
            ));
            $this->components->info('Created vite.config.js for Inertia React');

            return true;
        }

        $contents = $this->files->get($configPath);
        $hasInertiaImport = str_contains($contents, "@inertiajs/vite");
        $hasReactImport = str_contains($contents, '@vitejs/plugin-react');
        $hasInertiaInput = str_contains($contents, 'resources/js/app.tsx');
        $hasLegacyInput = str_contains($contents, 'resources/js/app.js');
        $hasLegacyEntry = $this->files->exists($this->laravel->basePath('resources/js/app.js'));

        if ($hasInertiaImport && $hasReactImport && $hasInertiaInput && (! $hasLegacyEntry || $hasLegacyInput)) {
            return true;
        }

        if ($this->option('force') || $this->looksLikeLaravelViteConfig($contents) || ($hasInertiaImport && $hasReactImport && $hasInertiaInput)) {
            if (! $hasInertiaImport) {
                $contents = "import inertia from '@inertiajs/vite';\n".$contents;
            }

            if (! $hasReactImport) {
                $contents = "import react from '@vitejs/plugin-react';\n".$contents;
            }

            // Keep Laravel's original app.js entrypoint. The default welcome
            // view still references it, while the generated Inertia root view
            // uses app.tsx. Building both entries lets the installer preserve
            // the existing application route instead of turning it into a
            // Vite manifest error.
            if ($hasLegacyEntry && ! $hasLegacyInput && $hasInertiaInput) {
                $contents = preg_replace_callback(
                    "/(['\"]resources\\/js\\/)app\\.tsx(['\"])/",
                    static fn (array $matches): string => $matches[1]."app.js', 'resources/js/app.tsx".$matches[2],
                    $contents,
                    1,
                ) ?? $contents;
            } elseif ($hasLegacyEntry && $hasLegacyInput && ! $hasInertiaInput) {
                $contents = preg_replace_callback(
                    "/(['\"]resources\\/js\\/)app\\.js(['\"])/",
                    static fn (array $matches): string => $matches[1]."app.js', 'resources/js/app.tsx".$matches[2],
                    $contents,
                    1,
                ) ?? $contents;
            }

            if (! str_contains($contents, 'inertia()') || ! str_contains($contents, 'react()')) {
                $plugins = [];
                if (! str_contains($contents, 'inertia()')) {
                    $plugins[] = '        inertia(),';
                }
                if (! str_contains($contents, 'react()')) {
                    $plugins[] = '        react(),';
                }
                $contents = preg_replace(
                    '/(plugins:\s*\[)/',
                    "$1\n".implode("\n", $plugins),
                    $contents,
                    1,
                ) ?? $contents;
            }

            $this->files->put($configPath, $contents);
            $this->components->info('Configured vite.config for Inertia React');

            return true;
        }

        $this->components->warn("Could not safely update {$configPath}; add the Inertia and React Vite plugins manually.");

        return false;
    }

    private function configureVueVite(): bool
    {
        $configPath = null;
        foreach (['vite.config.js', 'vite.config.mjs', 'vite.config.ts'] as $relative) {
            $candidate = $this->laravel->basePath($relative);
            if ($this->files->exists($candidate)) {
                $configPath = $candidate;

                break;
            }
        }

        if ($configPath === null) {
            $configPath = $this->laravel->basePath('vite.config.js');
            $this->files->put($configPath, $this->vueViteConfigSource(
                $this->files->exists($this->laravel->basePath('resources/js/app.js')),
            ));
            $this->components->info('Created vite.config.js for Inertia Vue');

            return true;
        }

        $contents = $this->files->get($configPath);
        $hasInertiaImport = str_contains($contents, "@inertiajs/vite");
        $hasVueImport = str_contains($contents, '@vitejs/plugin-vue');
        $hasInertiaInput = str_contains($contents, 'resources/js/app.ts');
        $hasLegacyEntry = $this->files->exists($this->laravel->basePath('resources/js/app.js'));
        $hasLegacyInput = str_contains($contents, 'resources/js/app.js');

        if ($hasInertiaImport && $hasVueImport && $hasInertiaInput && (! $hasLegacyEntry || $hasLegacyInput)) {
            return true;
        }

        if ($this->option('force') || $this->looksLikeLaravelViteConfig($contents) || str_contains($contents, 'laravel-vite-plugin')) {
            if (! $hasInertiaImport) {
                $contents = "import inertia from '@inertiajs/vite';\n".$contents;
            }

            if (! $hasVueImport) {
                $contents = "import vue from '@vitejs/plugin-vue';\n".$contents;
            }

            if (! $hasInertiaInput) {
                $contents = preg_replace_callback(
                    "/(['\"]resources\\/js\\/)app\\.js(['\"])/",
                    static fn (array $matches): string => $matches[1]."app.js', 'resources/js/app.ts".$matches[2],
                    $contents,
                    1,
                ) ?? $contents;

                if (! str_contains($contents, 'resources/js/app.ts')) {
                    $contents = preg_replace(
                        '/(input:\s*\[)/',
                        "$1'resources/js/app.ts', ",
                        $contents,
                        1,
                    ) ?? $contents;
                }
            }

            if (! str_contains($contents, 'inertia()')) {
                $contents = preg_replace('/(plugins:\s*\[)/', "$1\n        inertia(),", $contents, 1) ?? $contents;
            }

            if (! str_contains($contents, 'vue()')) {
                $contents = preg_replace('/(plugins:\s*\[)/', "$1\n        vue(),", $contents, 1) ?? $contents;
            }

            $this->files->put($configPath, $contents);
            $this->components->info('Configured vite.config for Inertia Vue');

            return true;
        }

        $this->components->warn("Could not safely update {$configPath}; add the Inertia and Vue Vite plugins manually.");

        return false;
    }

    private function vueViteConfigSource(bool $includeLegacyEntry): string
    {
        $inputs = $includeLegacyEntry
            ? "['resources/css/app.css', 'resources/js/app.js', 'resources/js/app.ts']"
            : "['resources/css/app.css', 'resources/js/app.ts']";

        $source = <<<'JS'
import inertia from '@inertiajs/vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: __INPUTS__,
            refresh: true,
        }),
        inertia(),
        vue(),
        tailwindcss(),
    ],
});
JS;

        return str_replace('__INPUTS__', $inputs, $source);
    }

    private function looksLikeLaravelViteConfig(string $contents): bool
    {
        return str_contains($contents, 'laravel-vite-plugin')
            && str_contains($contents, 'tailwindcss')
            && str_contains($contents, 'resources/js/app.js');
    }

    private function reactViteConfigSource(bool $includeLegacyEntry): string
    {
        $inputs = $includeLegacyEntry
            ? "['resources/css/app.css', 'resources/js/app.js', 'resources/js/app.tsx']"
            : "['resources/css/app.css', 'resources/js/app.tsx']";

        $source = <<<'JS'
import inertia from '@inertiajs/vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: __INPUTS__,
            refresh: true,
        }),
        inertia(),
        react(),
        tailwindcss(),
    ],
});
JS;

        return str_replace('__INPUTS__', $inputs, $source);
    }

    private function frontendPreflight(string $renderer = 'react'): bool
    {
        $missing = [];

        foreach (['package.json', 'resources/css/app.css'] as $relativePath) {
            if (! $this->files->exists($this->laravel->basePath($relativePath))) {
                $missing[] = $relativePath;
            }
        }

        if ($missing === []) {
            try {
                $package = json_decode(
                    $this->files->get($this->laravel->basePath('package.json')),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException) {
                $this->components->error('package.json is not valid JSON. No application files were changed.');

                return false;
            }

            if (! is_array($package)) {
                $this->components->error('package.json must contain a JSON object. No application files were changed.');

                return false;
            }

            if (! str_contains($this->files->get($this->laravel->basePath('resources/css/app.css')), 'tailwindcss')) {
                $this->components->error('resources/css/app.css does not import Tailwind CSS 4. No application files were changed.');
                $this->line("Add Tailwind CSS 4, or rerun with --renderer=none for a custom renderer.");

                return false;
            }

            return true;
        }

        $this->components->error("The {$renderer} preset needs ".implode(' and ', $missing).'.');
        $this->line('Add the missing Inertia frontend files, or rerun with --no-frontend for a custom renderer.');

        return false;
    }

    private function installFrontendDependencies(): bool
    {
        $basePath = $this->laravel->basePath();
        $command = $this->frontendInstallCommand();

        $this->components->info('Installing the official renderer with '.$command[0].'…');

        try {
            $process = new Process($command, $basePath, timeout: 600);
            $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));
        } catch (\Throwable $exception) {
            $this->components->error('The frontend package manager could not start: '.$exception->getMessage());

            return false;
        }

        if (! $process->isSuccessful()) {
            $this->components->error('Frontend dependency installation failed. Fix the package-manager error and run '.$command[0].' install again.');

            return false;
        }

        return true;
    }

    /** @return list<string> */
    private function frontendInstallCommand(): array
    {
        $basePath = $this->laravel->basePath();

        return match (true) {
            $this->files->exists($basePath.'/pnpm-lock.yaml') => ['pnpm', 'install'],
            $this->files->exists($basePath.'/yarn.lock') => ['yarn', 'install'],
            $this->files->exists($basePath.'/bun.lock'), $this->files->exists($basePath.'/bun.lockb') => ['bun', 'install'],
            default => ['npm', 'install'],
        };
    }

    private function frontendBuildCommand(): string
    {
        return match ($this->frontendInstallCommand()[0]) {
            'yarn' => 'yarn build',
            'bun' => 'bun run build',
            default => $this->frontendInstallCommand()[0].' run build',
        };
    }

    private function userResourceSource(string $appNamespace, string $panel): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$appNamespace}\Inlay\Resources;

use {$appNamespace}\Models\User;
use {$appNamespace}\Validation\UserRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inlay\Actions\Action;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Resources\Resource;
use Inlay\Resources\ResourceOperation;
use Inlay\Schemas\Components\Grid;
use Inlay\Schemas\Components\Section;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;

final class UserResource extends Resource
{
    protected static string \$model = User::class;

    protected static ?string \$navigationIcon = 'users';

    public static function globallySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function table(Table \$table): Table
    {
        return \$table
            ->searchPlaceholder('Search users…')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('email_verified_at')->label('Verified')->dateTime('M j, Y')->placeholder('Not verified')->sortable(),
                TextColumn::make('created_at')->label('Joined')->dateTime('M j, Y')->sortable(),
            ])
            ->actions([
                Action::make('edit')->url('/{$panel}/users/{id}/edit')->method('get'),
                Action::make('delete')
                    ->color('danger')
                    ->url('/{$panel}/users/{id}')
                    ->method('delete')
                    ->requiresConfirmation()
                    ->authorizeUsing(fn (Request \$request, User \$record): bool => ! \$record->is(\$request->user())),
            ])
            ->paginationPageOptions([10, 25, 50])
            ->emptyState('No users found', 'Create the first account for this panel.');
    }

    public static function form(Form \$form): Form
    {
        return \$form
            ->submitLabel('Save user')
            ->schema([
                Section::make('account')
                    ->label('Account details')
                    ->description('Manage the identity used to sign in to this panel.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')->required()->autofocus()->maxLength(255),
                            TextInput::make('email')->email()->required()->maxLength(255),
                            TextInput::make('password')
                                ->password()
                                ->revealable()
                                ->required(fn (string \$operation): bool => \$operation === 'create')
                                ->helperText('Required for new users. Leave blank while editing to keep the current password.')
                                ->minLength(8)
                                ->maxLength(255),
                        ]),
                    ]),
            ]);
    }

    public static function validation(): string
    {
        return UserRules::class;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    protected static function canAccess(ResourceOperation \$operation, ?Model \$record, mixed \$user): bool
    {
        if (! \$user instanceof User) {
            return false;
        }

        return \$operation !== ResourceOperation::Delete || ! \$record?->is(\$user);
    }

    /**
     * @param  array<string, mixed>  \$data
     * @return array<string, mixed>
     */
    protected static function mutateDataBeforeUpdate(array \$data, Model \$record): array
    {
        if ((\$data['password'] ?? '') === '') {
            unset(\$data['password']);
        }

        return \$data;
    }
}
PHP;
    }

    private function userPageSource(string $appNamespace, string $class, string $base, string $component): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$appNamespace}\Inlay\Resources;

use Inlay\Resources\Pages\{$base};

final class {$class} extends {$base}
{
    protected static string \$resource = UserResource::class;

    protected static string \$component = '{$component}';
}
PHP;
    }

    private function userRulesSource(string $appNamespace): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$appNamespace}\Validation;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationContext;

final class UserRules extends Validation
{
    public function rules(ValidationContext \$context): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore(\$context->record())],
            'password' => \$context->isOperation('create')
                ? ['required', 'string', 'min:8', 'max:255']
                : ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }

    public function prepare(array \$data, ValidationContext \$context): array
    {
        if (isset(\$data['name'])) {
            \$data['name'] = trim((string) \$data['name']);
        }

        if (isset(\$data['email'])) {
            \$data['email'] = Str::lower(trim((string) \$data['email']));
        }

        return \$data;
    }
}
PHP;
    }

    private function updateFrontendDependencies(string $path, string $renderer = 'react', bool $installMedia = false): bool
    {
        try {
            $package = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->components->error('package.json is not valid JSON, so frontend dependencies were not changed.');

            return false;
        }

        if (! is_array($package)) {
            $this->components->error('package.json must contain a JSON object.');

            return false;
        }

        $dependencies = is_array($package['dependencies'] ?? null) ? $package['dependencies'] : [];
        $requiredDependencies = $renderer === 'vue' ? [
            '@inertiajs/vue3' => '^3.0.0',
            '@inertiajs/vite' => '^3.0.0',
            '@inlayphp/actions' => '^0.3.0',
            '@inlayphp/actions-vue' => '^0.3.0',
            '@inlayphp/core' => '^0.3.0',
            '@inlayphp/forms-vue' => '^0.3.0',
            '@inlayphp/panels-vue' => '^0.3.0',
            '@inlayphp/resources' => '^0.3.0',
            '@inlayphp/resources-vue' => '^0.3.0',
            '@inlayphp/tables-vue' => '^0.3.0',
            '@inlayphp/theme' => '^0.3.0',
            '@inlayphp/ui' => '^0.3.0',
            '@inlayphp/ui-vue' => '^0.3.0',
            '@inlayphp/widgets-vue' => '^0.3.0',
            'vue' => '^3.5.0',
        ] : [
            '@inertiajs/react' => '^3.0.0',
            '@inertiajs/vite' => '^3.0.0',
            '@inlayphp/actions' => '^0.3.0',
            '@inlayphp/actions-react' => '^0.3.0',
            '@inlayphp/core' => '^0.3.0',
            '@inlayphp/forms-react' => '^0.3.0',
            '@inlayphp/panels-react' => '^0.3.0',
            '@inlayphp/resources' => '^0.3.0',
            '@inlayphp/resources-react' => '^0.3.0',
            '@inlayphp/tables-react' => '^0.3.0',
            '@inlayphp/theme' => '^0.3.0',
            '@inlayphp/ui' => '^0.3.0',
            '@inlayphp/ui-react' => '^0.3.0',
            '@inlayphp/widgets-react' => '^0.3.0',
            'react' => '^19.0.0',
            'react-dom' => '^19.0.0',
        ];
        if ($installMedia) {
            $requiredDependencies[$renderer === 'vue'
                ? '@inlayphp/media-manager-vue'
                : '@inlayphp/media-manager-react'] = '^0.3.0';
        }

        foreach ($requiredDependencies as $name => $version) {
            $dependencies[$name] ??= $version;
        }

        $devDependencies = is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : [];
        $requiredDevDependencies = $renderer === 'vue' ? [
            '@vitejs/plugin-vue' => '^6.0.0',
            'typescript' => '^5.7.0',
            'vue-tsc' => '^2.2.0',
        ] : [
            '@vitejs/plugin-react' => '^6.0.0',
            '@types/react' => '^19.0.0',
            '@types/react-dom' => '^19.0.0',
            'typescript' => '^5.7.0',
        ];
        foreach ($requiredDevDependencies as $name => $version) {
            $devDependencies[$name] ??= $version;
        }

        ksort($dependencies);
        ksort($devDependencies);
        $package['dependencies'] = $dependencies;
        $package['devDependencies'] = $devDependencies;
        $encoded = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $this->files->put($path, $encoded);
        $this->components->info('Added the official '.ucfirst($renderer).' renderer packages to package.json');

        return true;
    }

    /** @return array<string, string> */
    private function reactFrontendFiles(string $panel, bool $installMedia = false): array
    {
        $stubs = $this->laravel->basePath('vendor/inlayphp/inlay/stubs/react');
        if (! $this->files->isDirectory($stubs)) {
            $stubs = dirname(__DIR__).'/stubs/react';
        }

        $files = [
            'resources/js/layouts/inlay-panel-layout.tsx' => 'inlay-panel-layout.tsx',
            'resources/js/pages/inlay/auth/login.tsx' => 'login.tsx',
            'resources/js/pages/inlay/dashboard.tsx' => 'dashboard.tsx',
            'resources/js/pages/inlay/account-settings.tsx' => 'account-settings.tsx',
            'resources/js/pages/users/index.tsx' => 'users-index.tsx',
            'resources/js/pages/users/form.tsx' => 'users-form.tsx',
        ];
        if ($installMedia) {
            $files['resources/js/pages/inlay-media-manager/index.tsx'] = 'media-manager.tsx';
        }

        $rendered = array_map(
            fn (string $stub): string => str_replace(
                ['./inlay-panel-layout', '{{ panel }}'],
                ['../../layouts/inlay-panel-layout', $panel],
                $this->files->get($stubs.'/'.$stub),
            ),
            $files,
        );

        if ($installMedia) {
            $rendered['resources/js/pages/inlay/dashboard.tsx'] = str_replace(
                '["Users", "Create and manage panel accounts.", "/'.$panel.'/users"],',
                "[\"Users\", \"Create and manage panel accounts.\", \"/{$panel}/users\"],\n  [\"Media\", \"Upload and organize application media.\", \"/{$panel}/media\"],",
                $rendered['resources/js/pages/inlay/dashboard.tsx'],
            );
        }

        return $rendered;
    }

    /** @return array<string, string> */
    private function vueFrontendFiles(string $panel, bool $installMedia = false): array
    {
        $stubs = $this->laravel->basePath('vendor/inlayphp/inlay/stubs/vue');
        if (! $this->files->isDirectory($stubs)) {
            $stubs = dirname(__DIR__).'/stubs/vue';
        }

        $files = [
            'resources/js/layouts/inlay-panel-layout.vue' => 'inlay-panel-layout.vue',
            'resources/js/pages/inlay/auth/login.vue' => 'login.vue',
            'resources/js/pages/inlay/dashboard.vue' => 'dashboard.vue',
            'resources/js/pages/inlay/account-settings.vue' => 'account-settings.vue',
            'resources/js/pages/users/index.vue' => 'users-index.vue',
            'resources/js/pages/users/form.vue' => 'users-form.vue',
        ];
        if ($installMedia) {
            $files['resources/js/pages/inlay-media-manager/index.vue'] = 'media-manager.vue';
        }

        $rendered = array_map(
            fn (string $stub): string => str_replace('{{ panel }}', $panel, $this->files->get($stubs.'/'.$stub)),
            $files,
        );

        if ($installMedia) {
            $rendered['resources/js/pages/inlay/dashboard.vue'] = str_replace(
                "    ['Users', 'Create and manage panel accounts.', '/{$panel}/users'],",
                "    ['Users', 'Create and manage panel accounts.', '/{$panel}/users'],\n    ['Media', 'Upload and organize application media.', '/{$panel}/media'],",
                $rendered['resources/js/pages/inlay/dashboard.vue'],
            );
        }

        return $rendered;
    }

    private function configureTailwindSources(): bool
    {
        $path = $this->laravel->basePath('resources/css/app.css');
        if (! $this->files->exists($path)) {
            $this->components->error('resources/css/app.css is missing, so Inlay component styles cannot be generated.');

            return false;
        }

        $source = "@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';";
        $contents = $this->files->get($path);
        if (str_contains($contents, $source)) {
            return true;
        }

        $this->files->put($path, rtrim($contents)."\n\n{$source}\n");
        $this->components->info('Configured Tailwind to scan the installed Inlay renderer packages');

        return true;
    }
}
