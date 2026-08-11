<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Inlay\Authorization\AbilityDefinition;
use Inlay\Authorization\AbilityRegistry;
use Inlay\AuthorizationSpatie\Contracts\TeamResolver;
use Inlay\AuthorizationSpatie\Middleware\SetPermissionTeam;
use Inlay\AuthorizationSpatie\SpatiePermissionSynchronizer;
use Inlay\AuthorizationSpatie\TeamResolvers\SessionTeamResolver;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizationSpatieTestPermission extends Model implements Permission
{
    public $timestamps = false;

    protected $table = 'permissions';

    protected $guarded = [];

    public function roles(): BelongsToMany
    {
        throw new LogicException('Relationships are not required by synchronization tests.');
    }

    public static function findByName(BackedEnum|string $name, ?string $guardName): self
    {
        throw PermissionDoesNotExist::create($name instanceof BackedEnum ? (string) $name->value : $name, $guardName ?? 'web');
    }

    public static function findById(int|string $id, ?string $guardName): self
    {
        throw PermissionDoesNotExist::withId($id, $guardName ?? 'web');
    }

    public static function findOrCreate(BackedEnum|string $name, ?string $guardName): self
    {
        return self::query()->firstOrCreate([
            'name' => $name instanceof BackedEnum ? (string) $name->value : $name,
            'guard_name' => $guardName ?? 'web',
        ]);
    }
}

final class AuthorizationSpatieTestRegistrar extends PermissionRegistrar
{
    public int $cacheFlushes = 0;

    public int|string|null $teamId = null;

    public function __construct() {}

    public function forgetCachedPermissions(): bool
    {
        $this->cacheFlushes++;

        return true;
    }

    /**
     * Spatie's registrar parameter is untyped in v6 and typed in later major
     * versions. Keeping the test double untyped lets the supported range load
     * on the lowest-dependencies CI matrix without violating either contract.
     */
    public function setPermissionsTeamId($id): void
    {
        $this->teamId = $id instanceof Model ? $id->getKey() : $id;
    }

    public function getPermissionsTeamId(): int|string|null
    {
        return $this->teamId;
    }
}

final class AuthorizationSpatieTestUser extends Model
{
    protected $guarded = [];
}

/**
 * @template T
 *
 * @param  Closure(Capsule): T  $callback
 * @return T
 */
function withAuthorizationSpatieDatabase(Closure $callback): mixed
{
    $original = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository([
        'permission' => ['models' => ['permission' => AuthorizationSpatieTestPermission::class]],
        'inlay-authorization-spatie' => [
            'teams' => [
                'session_key' => 'active_team_id',
                'user_relations' => ['roles', 'permissions'],
            ],
        ],
    ]));
    Container::setInstance($container);

    $database = new Capsule($container);
    $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $database->setAsGlobal();
    $database->bootEloquent();
    $database->schema()->create('permissions', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->unique(['name', 'guard_name']);
    });

    try {
        return $callback($database);
    } finally {
        Container::setInstance($original);
    }
}

it('previews permission changes without writing or flushing the cache', function (): void {
    withAuthorizationSpatieDatabase(function (): void {
        AuthorizationSpatieTestPermission::query()->insert([
            ['name' => 'posts.view', 'guard_name' => 'web'],
            ['name' => 'legacy.use', 'guard_name' => 'web'],
        ]);
        $abilities = (new AbilityRegistry)
            ->register(AbilityDefinition::make('posts.view'), 'posts')
            ->register(AbilityDefinition::make('posts.edit'), 'posts');
        $registrar = new AuthorizationSpatieTestRegistrar;

        $result = (new SpatiePermissionSynchronizer($abilities, $registrar))->sync('web', dryRun: true, prune: true);

        expect($result->created)->toBe(['posts.edit'])
            ->and($result->existing)->toBe(['posts.view'])
            ->and($result->stale)->toBe(['legacy.use'])
            ->and($result->deleted)->toBe(['legacy.use'])
            ->and($result->dryRun)->toBeTrue()
            ->and(AuthorizationSpatieTestPermission::query()->count())->toBe(2)
            ->and($registrar->cacheFlushes)->toBe(0);
    });
});

it('creates and prunes only the selected guard and flushes cache once for mutations', function (): void {
    withAuthorizationSpatieDatabase(function (): void {
        AuthorizationSpatieTestPermission::query()->insert([
            ['name' => 'posts.view', 'guard_name' => 'web'],
            ['name' => 'legacy.use', 'guard_name' => 'web'],
            ['name' => 'legacy.use', 'guard_name' => 'api'],
        ]);
        $abilities = (new AbilityRegistry)
            ->register(AbilityDefinition::make('posts.view'), 'posts')
            ->register(AbilityDefinition::make('posts.edit'), 'posts');
        $registrar = new AuthorizationSpatieTestRegistrar;
        $synchronizer = new SpatiePermissionSynchronizer($abilities, $registrar);

        $result = $synchronizer->sync('web', prune: true);

        expect($result->created)->toBe(['posts.edit'])
            ->and($result->deleted)->toBe(['legacy.use'])
            ->and(AuthorizationSpatieTestPermission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all())
            ->toBe(['posts.edit', 'posts.view'])
            ->and(AuthorizationSpatieTestPermission::query()->where('guard_name', 'api')->pluck('name')->all())
            ->toBe(['legacy.use'])
            ->and($registrar->cacheFlushes)->toBe(1);

        $synchronizer->sync('web', prune: true);
        expect($registrar->cacheFlushes)->toBe(1);
    });
});

it('rejects an empty permission guard', function (): void {
    withAuthorizationSpatieDatabase(function (): void {
        $synchronizer = new SpatiePermissionSynchronizer(new AbilityRegistry, new AuthorizationSpatieTestRegistrar);

        expect(fn () => $synchronizer->sync('  '))->toThrow(InvalidArgumentException::class);
    });
});

it('uses a session-selected team', function (): void {
    withAuthorizationSpatieDatabase(function (): void {
        $session = new Store('inlay-test', new ArraySessionHandler(120));
        $session->put('active_team_id', 42);
        $request = Request::create('/admin');
        $request->setLaravelSession($session);

        expect((new SessionTeamResolver)->resolve($request))->toBe(42);
    });
});

it('scopes team context to one request and clears loaded permission relations', function (): void {
    withAuthorizationSpatieDatabase(function (): void {
        $registrar = new AuthorizationSpatieTestRegistrar;
        $registrar->teams = true;
        $registrar->teamId = 7;
        $resolver = new class implements TeamResolver
        {
            public function resolve(Request $request): int|string|Model|null
            {
                return 42;
            }
        };
        $user = (new AuthorizationSpatieTestUser)->setRelations([
            'roles' => new Collection,
            'permissions' => new Collection,
        ]);
        $request = Request::create('/admin');
        $request->setUserResolver(fn (): AuthorizationSpatieTestUser => $user);
        $middleware = new SetPermissionTeam($registrar, $resolver);

        $response = $middleware->handle($request, function () use ($registrar, $user): Response {
            expect($registrar->getPermissionsTeamId())->toBe(42)
                ->and($user->relationLoaded('roles'))->toBeFalse()
                ->and($user->relationLoaded('permissions'))->toBeFalse();

            return new Response('ok');
        });

        expect($response->getContent())->toBe('ok')
            ->and($registrar->getPermissionsTeamId())->toBe(7);
    });
});

it('restores team context after an exception and is a no-op when teams are disabled', function (): void {
    withAuthorizationSpatieDatabase(function (): void {
        $registrar = new AuthorizationSpatieTestRegistrar;
        $registrar->teams = true;
        $registrar->teamId = 'original';
        $counter = (object) ['calls' => 0];
        $resolver = new class($counter) implements TeamResolver
        {
            public function __construct(public object $counter) {}

            public function resolve(Request $request): int|string|Model|null
            {
                $this->counter->calls++;

                return 'selected';
            }
        };
        $request = Request::create('/admin');
        $middleware = new SetPermissionTeam($registrar, $resolver);

        expect(fn () => $middleware->handle($request, fn (): never => throw new RuntimeException('failed')))
            ->toThrow(RuntimeException::class, 'failed')
            ->and($registrar->getPermissionsTeamId())->toBe('original')
            ->and($counter->calls)->toBe(1);

        $registrar->teams = false;
        $middleware->handle($request, fn (): Response => new Response('ok'));
        expect($counter->calls)->toBe(1)
            ->and($registrar->getPermissionsTeamId())->toBe('original');
    });
});
