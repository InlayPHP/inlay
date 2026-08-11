<?php

declare(strict_types=1);

namespace Inlay\Tables\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class MakeTablePageCommand extends Command
{
    protected $signature = 'make:inlay-table-page {name : Page class name, optionally namespaced with slashes} {--model= : Eloquent model the table lists} {--force : Overwrite existing files}';

    protected $description = 'Create a standalone Inlay table page and its route hint';

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
        $namespace = implode('\\', [$appNamespace, 'Inlay', 'Tables', ...$segments]);
        $directory = app_path('Inlay/Tables'.($segments === [] ? '' : '/'.implode('/', $segments)));
        $path = $directory.'/'.$class.'.php';

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->error("File already exists: {$path}");

            return self::FAILURE;
        }

        $model = trim((string) $this->option('model'), '\/ ');
        $modelBase = $model === '' ? 'Model' : class_basename($model);
        if ($model !== '' && preg_match('/^[A-Z][A-Za-z0-9_]*$/', $modelBase) !== 1) {
            $this->components->error('The model must end with a valid StudlyCase class name.');

            return self::FAILURE;
        }
        $modelClass = $model === ''
            ? $appNamespace.'\\Models\\'.Str::singular($class)
            : (str_contains($model, '\\') ? $model : $appNamespace.'\\Models\\'.$modelBase);

        $this->files->ensureDirectoryExists($directory);
        $this->files->put($path, $this->source(
            $namespace,
            $class,
            $this->component($segments, $class),
            $modelClass,
        ));

        $this->components->info("Created {$path}");
        $this->components->info("Register it: Route::inlayTable('/".Str::kebab($class)."', {$namespace}\\{$class}::class);");

        return self::SUCCESS;
    }

    /**
     * The query-string prefix. A leading verb such as `List` or `Manage` is
     * dropped so the URL reads `accounts_search`, not `list_accounts_search`.
     */
    private static function queryPrefix(string $class): string
    {
        $subject = preg_replace('/^(List|Manage|Browse|Show|View)(?=[A-Z])/', '', $class) ?? $class;

        return Str::snake(Str::pluralStudly($subject === '' ? $class : $subject));
    }

    /** @param list<string> $segments */
    private function component(array $segments, string $class): string
    {
        return implode('/', array_map(static fn (string $segment): string => Str::kebab($segment), [...$segments, $class]));
    }

    private function source(string $namespace, string $class, string $component, string $model): string
    {
        $modelBase = class_basename($model);
        $table = self::queryPrefix($class);

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use {$model};
        use Illuminate\\Database\\Eloquent\\Builder;
        use Illuminate\\Http\\Request;
        use Inlay\\Tables\\Columns\\TextColumn;
        use Inlay\\Tables\\Table;
        use Inlay\\Tables\\TablePage;

        final class {$class} extends TablePage
        {
            protected static string \$component = '{$component}';

            protected function name(): string
            {
                return '{$table}';
            }

            protected function table(Table \$table): Table
            {
                return \$table
                    ->columns([
                        TextColumn::make('name')->searchable()->sortable(),
                    ])
                    ->emptyState('Nothing here yet', 'Records will appear once they exist.');
            }

            /** @return Builder<{$modelBase}> */
            protected function query(Request \$request): Builder
            {
                return {$modelBase}::query();
            }
        }

        PHP;
    }
}
