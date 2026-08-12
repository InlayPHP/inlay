<?php

declare(strict_types=1);

namespace Inlay\Installer;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class DoctorCommand extends Command
{
    protected $signature = 'inlay:doctor
        {--production : Require a compiled frontend stylesheet containing Inlay utilities}';

    protected $description = 'Check an Inlay panel installation and its production assets';

    private int $failures = 0;

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->failures = 0;
        $basePath = $this->laravel->basePath();

        $configPath = $this->laravel->configPath('inlay-panels.php');
        $this->check(
            $this->files->exists($configPath) && str_contains($this->files->get($configPath), '::class'),
            'Panel provider registration',
            'Run php artisan inlay:install --panels.',
        );

        $providerSource = $this->providerSource($basePath.'/app/Providers/Inlay');
        $this->check(
            str_contains($providerSource, 'extends PanelProvider'),
            'Application-owned panel provider',
            'Run php artisan inlay:install --panels, or register a custom PanelProvider.',
        );

        $renderer = $this->renderer($basePath.'/package.json');
        $this->check(
            $renderer !== null,
            'Official frontend renderer dependency',
            'Install @inlayphp/panels-react or @inlayphp/panels-vue.',
        );

        $cssPath = $basePath.'/resources/css/app.css';
        $cssSource = $this->files->exists($cssPath) ? $this->files->get($cssPath) : '';
        $hasInlaySource = str_contains($cssSource, '@source')
            && str_contains($cssSource, 'node_modules/@inlayphp')
            && ($renderer !== 'vue' || str_contains($cssSource, 'vue'));
        $this->check(
            $hasInlaySource,
            'Tailwind source scanning for installed Inlay packages',
            "Add @source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}'; to resources/css/app.css.",
        );

        if (str_contains($providerSource, 'UserResource::class')) {
            $userPath = $basePath.'/app/Models/User.php';
            $userSource = $this->files->exists($userPath) ? $this->files->get($userPath) : '';
            $this->check(
                str_contains($userSource, 'PanelAccount') && $this->files->exists($basePath.'/app/Inlay/Resources/UserResource.php'),
                'User resource and panel account integration',
                'Rerun php artisan inlay:install --panels to restore missing generated files.',
            );
        }

        if (str_contains($providerSource, 'MediaManagerPlugin')) {
            $this->check(
                $this->hasMigration($basePath, 'create_inlay_media_tables.php')
                    && $this->hasMigration($basePath, 'create_inlay_media_collections.php'),
                'Bundled Media Manager migrations',
                'Rerun php artisan inlay:install --panels to publish the migrations.',
            );
        }

        $this->checkProductionAssets($basePath);

        if ($this->failures > 0) {
            $this->newLine();
            $this->components->error("Inlay doctor found {$this->failures} problem(s).");

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Inlay is ready.');

        return self::SUCCESS;
    }

    private function check(bool $condition, string $label, string $solution): void
    {
        if ($condition) {
            $this->components->info("PASS  {$label}");

            return;
        }

        $this->failures++;
        $this->components->error("FAIL  {$label}");
        $this->line("      {$solution}");
    }

    private function providerSource(string $directory): string
    {
        if (! $this->files->isDirectory($directory)) {
            return '';
        }

        $sources = [];
        foreach ($this->files->allFiles($directory) as $file) {
            if ($file->getExtension() === 'php') {
                $sources[] = $this->files->get($file->getPathname());
            }
        }

        return implode("\n", $sources);
    }

    private function renderer(string $packagePath): ?string
    {
        if (! $this->files->exists($packagePath)) {
            return null;
        }

        try {
            $package = json_decode($this->files->get($packagePath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $dependencies = [];
        foreach (['dependencies', 'devDependencies'] as $group) {
            if (is_array($package) && is_array($package[$group] ?? null)) {
                $dependencies = [...$dependencies, ...$package[$group]];
            }
        }

        return match (true) {
            isset($dependencies['@inlayphp/panels-react']) => 'react',
            isset($dependencies['@inlayphp/panels-vue']) => 'vue',
            default => null,
        };
    }

    private function checkProductionAssets(string $basePath): void
    {
        $manifestPath = $basePath.'/public/build/manifest.json';
        if (! $this->files->exists($manifestPath)) {
            if ($this->option('production')) {
                $this->check(false, 'Compiled production assets', 'Run your frontend build command, then rerun inlay:doctor --production.');
            } else {
                $this->components->warn('SKIP  Compiled production assets (run inlay:doctor --production after building)');
            }

            return;
        }

        try {
            $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->check(false, 'Compiled production assets', 'Rebuild the frontend; public/build/manifest.json is invalid.');

            return;
        }

        $compiledCss = '';
        foreach ($this->stylesheetPaths($manifest) as $relativeCss) {
            $assetPath = $basePath.'/public/build/'.$relativeCss;
            if ($this->files->exists($assetPath)) {
                $compiledCss .= $this->files->get($assetPath);
            }
        }

        $hasUtilities = str_contains($compiledCss, '.text-\\(--inlay-text\\)')
            && str_contains($compiledCss, '.ring-\\(--inlay-border\\)')
            && str_contains($compiledCss, '.min-h-\\(--inlay-control-height\\)');

        $this->check(
            $hasUtilities,
            'Compiled production assets contain Inlay utilities',
            'Check the Inlay @source rule in resources/css/app.css, then rebuild the frontend.',
        );
    }

    /** @return list<string> */
    private function stylesheetPaths(mixed $manifest): array
    {
        if (! is_array($manifest)) {
            return [];
        }

        $paths = [];
        foreach ($manifest as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (is_string($entry['file'] ?? null)) {
                $paths[] = $entry['file'];
            }

            if (is_array($entry['css'] ?? null)) {
                foreach ($entry['css'] as $path) {
                    if (is_string($path)) {
                        $paths[] = $path;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(
            $paths,
            fn (string $path): bool => str_ends_with($path, '.css')
                && ! str_contains($path, '..')
                && ! str_starts_with($path, '/'),
        )));
    }

    private function hasMigration(string $basePath, string $suffix): bool
    {
        return $this->files->glob($basePath.'/database/migrations/*_'.$suffix) !== [];
    }
}
