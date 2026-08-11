<?php

declare(strict_types=1);

namespace Inlay\Forms\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Generate a reusable schema fragment.
 *
 * The generated class implements ProvidesSchema, so it embeds directly in any
 * Form, Infolist, or layout container without an array-returning helper.
 */
final class MakeSchemaCommand extends Command
{
    protected $signature = 'make:inlay-schema {name : Fragment class name, optionally namespaced with slashes} {--section= : Wrap the fragment in a Section bound to this state path} {--force : Overwrite existing files}';

    protected $description = 'Create a reusable Inlay schema fragment';

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
                $this->components->error('Each fragment name segment must be a StudlyCase class name.');

                return self::FAILURE;
            }
        }

        if ($segments === []) {
            $this->components->error('A fragment name is required.');

            return self::FAILURE;
        }

        $section = $this->section();
        if ($section === false) {
            $this->components->error('The section state path may only contain dot-separated letters, numbers, underscores, and hyphens.');

            return self::FAILURE;
        }

        $class = array_pop($segments);
        $appNamespace = rtrim($this->laravel->getNamespace(), '\\');
        $namespace = implode('\\', [$appNamespace, 'Inlay', 'Schemas', ...$segments]);
        $directory = app_path('Inlay/Schemas'.($segments === [] ? '' : '/'.implode('/', $segments)));
        $path = $directory.'/'.$class.'.php';

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->error("File already exists: {$path}");

            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists($directory);
        $this->files->put($path, $this->source($namespace, $class, $section));

        $this->components->info("Created {$path}");
        $this->components->info("Embed it: ->schema([new {$namespace}\\{$class}]);");

        return self::SUCCESS;
    }

    /** @return string|false|null */
    private function section(): string|false|null
    {
        $section = trim((string) $this->option('section'));
        if ($section === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $section) === 1 ? $section : false;
    }

    private function source(string $namespace, string $class, ?string $section): string
    {
        $sectionImport = $section === null ? '' : "use Inlay\\Schemas\\Components\\Section;\n";
        $components = $section === null
            ? <<<'PHP'
                        TextInput::make('name')->required()->maxLength(255),
            PHP
            : <<<PHP
                        Section::make('{$section}')
                            ->statePath('{$section}')
                            ->schema([
                                TextInput::make('name')->required()->maxLength(255),
                            ]),
            PHP;

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Inlay\\Forms\\Fields\\TextInput;
        {$sectionImport}use Inlay\\Schemas\\Contracts\\ProvidesSchema;

        final class {$class} implements ProvidesSchema
        {
            /** @return list<\\Inlay\\Schemas\\Component> */
            public function schemaComponents(): array
            {
                return [
        {$components}
                ];
            }
        }

        PHP;
    }
}
