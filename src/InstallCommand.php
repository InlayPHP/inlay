<?php

declare(strict_types=1);

namespace Inlay\Installer;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class InstallCommand extends Command
{
    protected $signature = 'inlay:install
        {--panel=admin : Panel identifier and URL segment}
        {--renderer=react : Frontend renderer hint: react, vue, or none}
        {--tenant-model= : Fully-qualified Eloquent tenant model (for example App\\Models\\Team)}
        {--tenant-parameter=tenant : Tenant route parameter name}
        {--tenant-route-key= : Tenant route key (defaults to the model route key)}
        {--path=app/Providers/Inlay : Relative directory for the generated panel provider}
        {--force : Overwrite the generated provider file}
        {--no-panel : Only create or update the panel configuration}';

    protected $description = 'Install Inlay and scaffold a first panel provider';

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

        if ($panel === null || ! in_array($renderer, ['react', 'vue', 'none'], true) || $tenantParameter === null || $tenantRouteKey === false || $directory === null) {
            $this->components->error('The panel, renderer, tenant, and output path must use safe values.');

            return self::FAILURE;
        }

        if ($this->option('tenant-model') !== null && $tenantModel === null) {
            $this->components->error('The tenant model must be a fully-qualified Eloquent model class.');

            return self::FAILURE;
        }

        $appNamespace = rtrim((string) $this->laravel->getNamespace(), '\\');
        $class = Str::studly(str_replace('-', ' ', $panel)).'PanelProvider';
        $namespace = $this->providerNamespace($appNamespace, $directory);
        $fqcn = $namespace.'\\'.$class;
        $providerPath = $this->laravel->basePath($directory).DIRECTORY_SEPARATOR.$class.'.php';

        if (! $this->option('no-panel')) {
            if ($this->files->exists($providerPath) && ! $this->option('force')) {
                $this->components->error("Panel provider already exists: {$providerPath}. Use --force to overwrite it.");

                return self::FAILURE;
            }

            $this->files->ensureDirectoryExists(dirname($providerPath));
            $this->files->put($providerPath, $this->providerSource($namespace, $class, $panel, $tenantModel, $tenantParameter, $tenantRouteKey ?: null));
            $this->components->info("Created {$fqcn}");
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
        $this->components->info('Inlay is installed. Panel routes will be registered automatically by the provider.');

        if ($renderer === 'react') {
            $this->line('Next: pnpm add @inlayphp/panels-react and resolve the inlayPanel prop with <Panel />.');
        } elseif ($renderer === 'vue') {
            $this->line('Next: pnpm add @inlayphp/panels-vue and resolve the inlayPanel prop with <Panel />.');
        } else {
            $this->line('Next: install one official renderer or provide your own renderer for inlay.panels.v1.');
        }

        $this->line('Then register Resources with ->resources([...]) or use php artisan make:inlay-resource User.');

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

    /** @return string|false|null */
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

    private function providerSource(string $namespace, string $class, string $panel, ?string $tenantModel, string $tenantParameter, ?string $tenantRouteKey): string
    {
        $path = '/'.$panel;
        $tenantImport = $tenantModel === null ? '' : "use {$tenantModel};\n";
        $tenantClass = $tenantModel === null ? '' : Str::afterLast($tenantModel, '\\');
        $tenant = $tenantModel === null
            ? ''
            : "            ->tenant({$tenantClass}::class, parameter: '{$tenantParameter}'".($tenantRouteKey === null ? '' : ", routeKey: '{$tenantRouteKey}'").")\n";

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Inlay\\Panel;
use Inlay\\PanelProvider;
{$tenantImport}

final class {$class} extends PanelProvider
{
    public function panel(Panel \$panel): Panel
    {
        return \$panel
            ->path('{$path}')
{$tenant}            ->brandName((string) config('app.name', 'Inlay'))
            ->sidebarNavigation()
            ->collapsible()
            ->breadcrumbs()
            ->topbar()
            ->middleware(['web'])
            ->authMiddleware(['auth'])
            ->spa()
            ->renderComponent('PanelLayout');
    }

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
}
