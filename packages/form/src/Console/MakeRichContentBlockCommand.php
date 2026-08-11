<?php

declare(strict_types=1);

namespace Inlay\Forms\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class MakeRichContentBlockCommand extends Command
{
    protected $signature = 'make:inlay-rich-content-block
        {name : The block class name, optionally including subdirectories}
        {--force : Overwrite an existing rich content block}';

    protected $description = 'Create an application-owned Inlay rich content custom block';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $parts = $this->blockParts((string) $this->argument('name'));
        if ($parts === null) {
            $this->components->error('The name must contain valid class or directory segments.');

            return self::FAILURE;
        }

        [$directories, $class] = $parts;
        $appNamespace = rtrim($this->laravel->getNamespace(), '\\');
        $namespace = $appNamespace.'\\Inlay\\RichContent'.($directories === [] ? '' : '\\'.implode('\\', $directories));
        $relativeDirectory = 'Inlay'.DIRECTORY_SEPARATOR.'RichContent'.($directories === [] ? '' : DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $directories));
        $path = rtrim((string) $this->laravel->make('path'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$relativeDirectory.DIRECTORY_SEPARATOR.$class.'.php';

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->error("Rich content block already exists: {$path}");

            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $this->source($namespace, $class, Str::headline(Str::beforeLast($class, 'Block'))));
        $this->components->info("Created {$namespace}\\{$class}");

        return self::SUCCESS;
    }

    /** @return array{list<string>, string}|null */
    private function blockParts(string $name): ?array
    {
        $segments = explode('/', trim(str_replace('\\', '/', $name), '/ '));
        if ($segments === ['']) {
            return null;
        }
        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $segment) !== 1) {
                return null;
            }
        }
        $segments = array_map(static fn (string $segment): string => Str::studly($segment), $segments);
        $class = array_pop($segments);
        if (! str_ends_with($class, 'Block')) {
            $class .= 'Block';
        }

        return [$segments, $class];
    }

    private function source(string $namespace, string $class, string $label): string
    {
        $id = Str::kebab(Str::beforeLast($class, 'Block'));

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Illuminate\Contracts\Support\Htmlable;
use Inlay\Forms\Fields\RichEditor\RichContentCustomBlock;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;

final class {$class} extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return '{$id}';
    }

    public static function getLabel(): string
    {
        return '{$label}';
    }

    public static function configureEditorForm(Form \$form): Form
    {
        return \$form->schema([
            TextInput::make('heading')->required()->maxLength(120),
        ]);
    }

    public static function toHtml(array \$config, array \$data = []): Htmlable|string
    {
        return view('rich-content.{$id}', ['config' => \$config, 'data' => \$data]);
    }
}
PHP;
    }
}
