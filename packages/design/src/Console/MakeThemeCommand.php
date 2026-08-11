<?php

declare(strict_types=1);

namespace Inlay\Design\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Inlay\Design\Design;

final class MakeThemeCommand extends Command
{
    protected $signature = 'make:inlay-theme
        {name : The theme name, optionally including subdirectories}
        {--path=app/Inlay/Themes : Relative directory for generated PHP theme classes}
        {--css-path=resources/css/inlay : Relative directory for generated CSS files}
        {--force : Overwrite existing theme files}';

    protected $description = 'Create an application-owned Inlay theme class and CSS variables';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $parts = $this->themeParts((string) $this->argument('name'));
        $classRoot = $this->relativeDirectory((string) $this->option('path'));
        $cssRoot = $this->relativeDirectory((string) $this->option('css-path'));

        if ($parts === null || $classRoot === null || $cssRoot === null) {
            $this->components->error('The name and output paths must contain safe relative segments.');

            return self::FAILURE;
        }

        [$directories, $class, $slug] = $parts;
        $appNamespace = rtrim((string) $this->laravel->getNamespace(), '\\');
        $namespace = $this->themeNamespace($appNamespace, $classRoot, $directories);
        $directory = rtrim($this->laravel->basePath($classRoot), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $directories);
        $classPath = $directory.DIRECTORY_SEPARATOR.$class.'.php';
        $cssPath = rtrim($this->laravel->basePath($cssRoot), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$slug.'.css';

        if ((! $this->option('force')) && ($this->files->exists($classPath) || $this->files->exists($cssPath))) {
            $this->components->error('A generated theme file already exists. Use --force to overwrite it.');

            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists(dirname($classPath));
        $this->files->ensureDirectoryExists(dirname($cssPath));
        $this->files->put($classPath, $this->source($namespace, $class, $slug));
        $this->files->put($cssPath, Design::css(Design::default()->named($slug)));
        $this->components->info("Created {$namespace}\\{$class}");
        $this->components->info("Created {$cssPath}");

        return self::SUCCESS;
    }

    /** @return array{list<string>, string, string}|null */
    private function themeParts(string $name): ?array
    {
        $name = trim(str_replace('\\', '/', $name));
        if ($name === '' || str_starts_with($name, '/')) {
            return null;
        }

        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $segment) !== 1) {
                return null;
            }
        }

        $slug = Str::kebab(implode(' ', $segments));
        $segments = array_map(static fn (string $segment): string => Str::studly($segment), $segments);
        $class = array_pop($segments).'Theme';

        return [$segments, $class, $slug];
    }

    private function relativeDirectory(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\/]/', $path) === 1) {
            return null;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return implode(DIRECTORY_SEPARATOR, $segments);
    }

    /** @param list<string> $directories */
    private function themeNamespace(string $appNamespace, string $classRoot, array $directories): string
    {
        $segments = explode('/', str_replace(DIRECTORY_SEPARATOR, '/', $classRoot));
        $namespaceSegments = $segments[0] === 'app' ? array_slice($segments, 1) : ['Inlay', 'Themes'];
        $namespaceSegments = [...$namespaceSegments, ...$directories];

        return $appNamespace.($namespaceSegments === [] ? '' : '\\'.implode('\\', $namespaceSegments));
    }

    private function source(string $namespace, string $class, string $slug): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Inlay\\Design\\Design;
use Inlay\\Theme\\Theme;

final class {$class}
{
    public static function make(): Theme
    {
        return Design::default()->named('{$slug}');
    }
}
PHP;
    }
}
