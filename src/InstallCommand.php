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
        {--panels : Install the complete administration panel preset}
        {--panel=admin : Panel identifier and URL segment}
        {--renderer=react : Frontend renderer hint: react, vue, or none}
        {--tenant-model= : Fully-qualified Eloquent tenant model (for example App\\Models\\Team)}
        {--tenant-parameter=tenant : Tenant route parameter name}
        {--tenant-route-key= : Tenant route key (defaults to the model route key)}
        {--path=app/Providers/Inlay : Relative directory for the generated panel provider}
        {--force : Overwrite the generated provider file}
        {--without-media : Do not install the bundled media library}
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
        $installMedia = ! (bool) $this->option('without-media');
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

        $appNamespace = rtrim((string) $this->laravel->getNamespace(), '\\');
        $class = Str::studly(str_replace('-', ' ', $panel)).'PanelProvider';
        $namespace = $this->providerNamespace($appNamespace, $directory);
        $fqcn = $namespace.'\\'.$class;
        $providerPath = $this->laravel->basePath($directory).DIRECTORY_SEPARATOR.$class.'.php';

        if ($createPanel) {
            if ($this->files->exists($providerPath) && ! $this->option('force')) {
                $this->components->error("Panel provider already exists: {$providerPath}. Use --force to overwrite it.");

                return self::FAILURE;
            }

            if ($installUsers && ! $this->canScaffoldUserResource($appNamespace)) {
                return self::FAILURE;
            }

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

            if ($installUsers && ! $this->scaffoldUserResource($appNamespace, $panel)) {
                return self::FAILURE;
            }

            if ($installMedia && ! $this->publishMediaMigrations()) {
                return self::FAILURE;
            }

            $this->configureUserModel($appNamespace);
            $this->configureGuestRedirect($panel);

            if ($renderer !== 'none' && ! $this->option('no-frontend')) {
                $frontendReady = $this->scaffoldFrontend($renderer, $panel);
                if ($frontendReady && $renderer === 'react' && ! $this->option('no-npm') && ! $this->installFrontendDependencies()) {
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
            $this->line('Frontend: the official React packages and page wrappers are ready. Run your normal build command.');
        } elseif ($renderer === 'vue') {
            $this->line('Next: pnpm add @inlayphp/panels-vue and resolve the inlayPanel prop with <Panel />.');
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
{$tenant}            ->brandName((string) config('app.name', 'Inlay'))
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
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $source);
            $this->components->info('Created '.Str::after($path, $this->laravel->basePath().DIRECTORY_SEPARATOR));
        }

        return true;
    }

    private function canScaffoldUserResource(string $appNamespace): bool
    {
        if ($this->option('force')) {
            return true;
        }

        foreach (array_keys($this->userResourceFiles(rtrim($appNamespace, '\\'), 'unused')) as $path) {
            if (! $this->files->exists($path)) {
                continue;
            }

            $this->components->error("User resource file already exists: {$path}. Use --force to overwrite it.");

            return false;
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
                $implements = $interfaces === '' ? ' implements PanelAccount' : $interfaces.', PanelAccount';

                return "class User extends Authenticatable{$implements}\n{\n    use InteractsWithPanelAccount;";
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

    private function scaffoldFrontend(string $renderer, string $panel): bool
    {
        if ($renderer !== 'react') {
            $this->components->warn('The turnkey frontend preset currently targets React. Vue remains available through the documented manual adapter setup.');

            return false;
        }

        $packagePath = $this->laravel->basePath('package.json');
        if (! $this->files->exists($packagePath)) {
            $this->components->warn('package.json was not found. Install the React adapter after adding Inertia to the application.');

            return false;
        }

        if (! $this->updateFrontendDependencies($packagePath)) {
            return false;
        }

        foreach ($this->reactFrontendFiles($panel) as $relative => $source) {
            $path = $this->laravel->basePath($relative);
            if ($this->files->exists($path) && ! $this->option('force')) {
                $this->components->warn("Frontend file already exists and was not replaced: {$relative}");

                continue;
            }

            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $source);
            $this->components->info("Created {$relative}");
        }

        $this->configureTailwindSources();

        return true;
    }

    private function installFrontendDependencies(): bool
    {
        $basePath = $this->laravel->basePath();
        $command = match (true) {
            $this->files->exists($basePath.'/pnpm-lock.yaml') => ['pnpm', 'install'],
            $this->files->exists($basePath.'/yarn.lock') => ['yarn', 'install'],
            $this->files->exists($basePath.'/bun.lock'), $this->files->exists($basePath.'/bun.lockb') => ['bun', 'install'],
            default => ['npm', 'install'],
        };

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

    private function updateFrontendDependencies(string $path): bool
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
        foreach ([
            '@inlayphp/actions' => '^0.3.0',
            '@inlayphp/actions-react' => '^0.3.0',
            '@inlayphp/core' => '^0.3.0',
            '@inlayphp/forms-react' => '^0.3.0',
            '@inlayphp/media-manager-react' => '^0.3.0',
            '@inlayphp/panels-react' => '^0.3.0',
            '@inlayphp/resources' => '^0.3.0',
            '@inlayphp/resources-react' => '^0.3.0',
            '@inlayphp/tables-react' => '^0.3.0',
            '@inlayphp/theme' => '^0.3.0',
            '@inlayphp/ui' => '^0.3.0',
            '@inlayphp/ui-react' => '^0.3.0',
            '@inlayphp/widgets-react' => '^0.3.0',
        ] as $name => $version) {
            $dependencies[$name] ??= $version;
        }

        ksort($dependencies);
        $package['dependencies'] = $dependencies;
        $encoded = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $this->files->put($path, $encoded);
        $this->components->info('Added the official React renderer packages to package.json');

        return true;
    }

    /** @return array<string, string> */
    private function reactFrontendFiles(string $panel): array
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
            'resources/js/pages/inlay-media-manager/index.tsx' => 'media-manager.tsx',
            'resources/js/pages/users/index.tsx' => 'users-index.tsx',
            'resources/js/pages/users/form.tsx' => 'users-form.tsx',
        ];

        return array_map(
            fn (string $stub): string => str_replace('{{ panel }}', $panel, $this->files->get($stubs.'/'.$stub)),
            $files,
        );
    }

    private function configureTailwindSources(): void
    {
        $path = $this->laravel->basePath('resources/css/app.css');
        if (! $this->files->exists($path)) {
            $this->components->warn('Add Tailwind source scanning for node_modules/@inlayphp after creating resources/css/app.css.');

            return;
        }

        $source = "@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx}';";
        $contents = $this->files->get($path);
        if (str_contains($contents, $source)) {
            return;
        }

        $this->files->put($path, rtrim($contents)."\n\n{$source}\n");
        $this->components->info('Configured Tailwind to scan the Inlay React packages');
    }
}
