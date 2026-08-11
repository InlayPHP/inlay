<?php

declare(strict_types=1);

namespace Inlay\Validation\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class MakeValidationCommand extends Command
{
    protected $signature = 'make:inlay-validation
        {name : The validation class name, optionally including subdirectories}
        {--force : Overwrite an existing validation class}';

    protected $description = 'Create an application-owned Inlay validation class';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $parts = $this->validationParts((string) $this->argument('name'));

        if ($parts === null) {
            $this->components->error('The name must contain valid class or directory segments.');

            return self::FAILURE;
        }

        [$directories, $class] = $parts;
        $appNamespace = rtrim($this->laravel->getNamespace(), '\\');
        $namespace = $appNamespace.'\\Validation'.($directories === [] ? '' : '\\'.implode('\\', $directories));
        $relativeDirectory = 'Validation'.($directories === [] ? '' : DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $directories));
        $path = rtrim((string) $this->laravel->make('path'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$relativeDirectory.DIRECTORY_SEPARATOR.$class.'.php';

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->error("Validation class already exists: {$path}");

            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->source($namespace, $class));
        $this->components->info("Created {$namespace}\\{$class}");

        return self::SUCCESS;
    }

    /** @return array{list<string>, string}|null */
    private function validationParts(string $name): ?array
    {
        $name = trim(str_replace('\\', '/', $name), '/ ');

        if ($name === '') {
            return null;
        }

        $segments = explode('/', $name);

        foreach ($segments as $segment) {
            if ($segment === '' || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $segment) !== 1) {
                return null;
            }
        }

        $segments = array_map(static fn (string $segment): string => Str::studly($segment), $segments);
        $class = array_pop($segments);

        if (! str_ends_with($class, 'Rules')) {
            $class .= 'Rules';
        }

        return [$segments, $class];
    }

    private function source(string $namespace, string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Inlay\Validation\ValidationContext;
use Inlay\Validation\Validation;

final class {$class} extends Validation
{
    public function rules(ValidationContext \$context): array
    {
        return [
            // Define native Laravel validation rules here.
        ];
    }
}
PHP;
    }
}
