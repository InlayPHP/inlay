<?php

declare(strict_types=1);

namespace Inlay\Forms\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class MakeFormPageCommand extends Command
{
    protected $signature = 'make:inlay-form-page {name : Page class name, optionally namespaced with slashes} {--model= : Eloquent model the form saves} {--force : Overwrite existing files}';

    protected $description = 'Create a standalone Inlay form page and its route hint';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $input = trim(str_replace('\\', '/', (string) $this->argument('name')), '/ ');
        $segments = array_values(array_filter(explode('/', $input), static fn (string $segment): bool => $segment !== ''));

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Z][A-Za-z0-9_]*$/', $segment) !== 1) {
                $this->components->error('Each page name segment must be a StudlyCase class name.');

                return self::FAILURE;
            }
        }

        if ($segments === []) {
            $this->components->error('A page name is required.');

            return self::FAILURE;
        }

        $class = array_pop($segments);
        $appNamespace = rtrim($this->laravel->getNamespace(), '\\');
        $namespace = implode('\\', [$appNamespace, 'Inlay', 'Forms', ...$segments]);
        $directory = app_path('Inlay/Forms'.($segments === [] ? '' : '/'.implode('/', $segments)));
        $path = $directory.'/'.$class.'.php';
        $component = $this->component($segments, $class);

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->error("File already exists: {$path}");

            return self::FAILURE;
        }

        $model = $this->model($appNamespace);
        if ($model === false) {
            $this->components->error('The model must end with a valid StudlyCase class name.');

            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists($directory);
        $this->files->put($path, $this->source($namespace, $class, $component, $model));

        $this->components->info("Created {$path}");
        $this->components->info("Register it: Route::inlayForm('/".Str::kebab($class)."', {$namespace}\\{$class}::class);");

        return self::SUCCESS;
    }

    /** @param list<string> $segments */
    private function component(array $segments, string $class): string
    {
        $parts = array_map(static fn (string $segment): string => Str::kebab($segment), [...$segments, $class]);

        return implode('/', $parts);
    }

    /** @return string|false|null */
    private function model(string $appNamespace): string|false|null
    {
        $model = trim((string) $this->option('model'), '\/ ');
        if ($model === '') {
            return null;
        }

        $base = class_basename($model);
        if (preg_match('/^[A-Z][A-Za-z0-9_]*$/', $base) !== 1) {
            return false;
        }

        return str_contains($model, '\\') ? $model : $appNamespace.'\\Models\\'.$base;
    }

    private function source(string $namespace, string $class, string $component, ?string $model): string
    {
        $modelImport = $model === null ? '' : "use {$model};\n";
        $modelBase = $model === null ? null : class_basename($model);
        $submit = $model === null
            ? "        // Persist \$data here.\n\n        return back()->with('success', 'Saved.');"
            : "        {$modelBase}::query()->create(\$data);\n\n        return back()->with('success', '".Str::headline($modelBase)." created.');";

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        {$modelImport}use Illuminate\\Http\\RedirectResponse;
        use Illuminate\\Http\\Request;
        use Inlay\\Forms\\Fields\\TextInput;
        use Inlay\\Forms\\Form;
        use Inlay\\Forms\\FormPage;

        final class {$class} extends FormPage
        {
            protected static string \$component = '{$component}';

            protected function form(Form \$form): Form
            {
                return \$form
                    ->submitLabel('Save')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                    ]);
            }

            protected function submit(array \$data, Request \$request): RedirectResponse
            {
        {$submit}
            }
        }

        PHP;
    }
}
