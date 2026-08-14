<?php

declare(strict_types=1);

use Illuminate\Auth\Access\Gate;
use Illuminate\Container\Container;
use Illuminate\Container\Container as LaravelContainer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Inlay\Authorization\AbilityRegistry;
use Inlay\Resources\Contracts\HasTenants;
use Inlay\Resources\Http\Middleware\ResolveTenant;
use Inlay\Resources\Tenancy;
use Inlay\Routing\PanelRegistrar;

use Inlay\Authorization\AbilityDefinition;
use Inlay\Authorization\AuthorizationManager;
use Inlay\Auth\LoginAttempt;
use Inlay\Auth\LoginPipeline;
use Inlay\Auth\LoginStep;
use Inlay\Contracts\PanelUser;
use Inlay\Core\Contracts\Plugin;
use Inlay\Core\PluginContext;
use Inlay\NavigationGroup;
use Inlay\NavigationItem;
use Inlay\Panel;
use Inlay\PanelProvider;
use Inlay\PanelRegistry;
use Inlay\PanelRoute;
use Inlay\Support\Condition;
use Symfony\Component\HttpFoundation\Response;

function panelTestPlugin(string $id, ArrayObject $events): Plugin
{
    return new class($id, $events) implements Plugin
    {
        public function __construct(
            private readonly string $pluginId,
            private readonly ArrayObject $events,
        ) {}

        public function id(): string
        {
            return $this->pluginId;
        }

        public function register(PluginContext $context): void
        {
            $panel = $context->hostAs(Panel::class);
            $this->events->append("register:{$panel->id()}:{$this->pluginId}");
        }

        public function boot(PluginContext $context): void
        {
            $panel = $context->hostAs(Panel::class);
            $this->events->append("boot:{$panel->id()}:{$this->pluginId}");
        }
    };
}

it('runs panel login steps in order and lets a step continue the normal redirect', function (): void {
    $events = new ArrayObject;
    $first = new class($events) implements LoginStep
    {
        public function __construct(private readonly ArrayObject $events) {}

        public function handle(LoginAttempt $attempt, Closure $next): ?Response
        {
            $this->events->append('first:'.$attempt->panel->id());

            return $next();
        }
    };
    $second = new class($events) implements LoginStep
    {
        public function __construct(private readonly ArrayObject $events) {}

        public function handle(LoginAttempt $attempt, Closure $next): ?Response
        {
            $this->events->append('second:'.($attempt->remember ? 'remember' : 'session'));

            return $next();
        }
    };
    $panel = Panel::make('admin')->loginStep($first)->loginStep($second);

    $result = (new LoginPipeline(new Container))->process(
        new LoginAttempt(Request::create('/admin/login'), $panel, new stdClass, true),
        $panel->loginSteps(),
    );

    expect($result)->toBeNull()
        ->and($events->getArrayCopy())->toBe(['first:admin', 'second:remember']);
});

it('allows a login step to stop the pipeline for a challenge response', function (): void {
    $events = new ArrayObject;
    $panel = Panel::make('admin')->loginStep(new class($events) implements LoginStep
    {
        public function __construct(private readonly ArrayObject $events) {}

        public function handle(LoginAttempt $attempt, Closure $next): ?Response
        {
            $this->events->append('challenge');

            return new Response('challenge', 202);
        }
    })->loginStep(new class($events) implements LoginStep
    {
        public function __construct(private readonly ArrayObject $events) {}

        public function handle(LoginAttempt $attempt, Closure $next): ?Response
        {
            $this->events->append('must-not-run');

            return $next();
        }
    });

    $result = (new LoginPipeline(new Container))->process(
        new LoginAttempt(Request::create('/admin/login'), $panel, new stdClass, false),
        $panel->loginSteps(),
    );

    expect($result)->toBeInstanceOf(Response::class)
        ->and($result?->getStatusCode())->toBe(202)
        ->and($events->getArrayCopy())->toBe(['challenge']);
});

it('serializes a complete renderer-neutral panel contract with deterministic navigation', function (): void {
    $panel = Panel::make('admin')
        ->path('control')
        ->brandName('Inlay Admin')
        ->brandLogo('/images/logo.svg')
        ->colors(['primary' => '#2563eb', 'danger' => 'rgb(220 38 38)'])
        ->theme(['radius' => '0.75rem', 'sidebar-width' => 280, 'dark' => true])
        ->topNavigation()
        ->collapsible()
        ->breadcrumbs(false)
        ->topbar()
        ->middleware(['web', 'bindings', 'web'])
        ->authMiddleware(['auth', 'verified'])
        ->navigationGroups([
            NavigationGroup::make('settings')->label('Settings')->sort(20)->collapsed()->items([
                NavigationItem::make('roles')->label('Roles')->sort(20)->url('/admin/roles'),
                NavigationItem::make('users')->label('Users')->sort(10)->url('/admin/users'),
            ]),
            NavigationGroup::make('main')->label('Main')->sort(1)->collapsible(false),
        ])
        ->navigationItems([
            NavigationItem::make('audit')->label('Audit log')->group('settings')->sort(30),
            NavigationItem::make('dashboard')->label('Dashboard')->sort(1)->active(),
            NavigationItem::make('reports')->label('Reports')->group('reports')->sort(5),
        ])
        ->userMenuItems([
            NavigationItem::make('logout')->label('Sign out')->sort(20),
            NavigationItem::make('profile')->label('Profile')->sort(10),
        ])
        ->spa()
        ->renderComponent('AdminShell');
    $payload = json_decode(json_encode($panel, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)
        ->contract->toBe('inlay.panels.v1')
        ->type->toBe('panel')
        ->id->toBe('admin')
        ->path->toBe('/control')
        ->brandName->toBe('Inlay Admin')
        ->brandLogo->toBe('/images/logo.svg')
        ->colors->toBe(['primary' => '#2563eb', 'danger' => 'rgb(220 38 38)']);

    expect($payload['theme']['radius'])->toBe('0.75rem')
        ->and($payload['theme']['sidebar-width'])->toBe(280)
        ->and($payload['theme']['dark'])->toBeTrue()
        ->and($payload['themeName'])->toBe('default');

    expect($payload)
        ->navigationMode->toBe('top')
        ->collapsible->toBeTrue()
        ->breadcrumbs->toBeFalse()
        ->topbar->toBeTrue()
        ->spa->toBeTrue()
        ->renderComponent->toBe('AdminShell')
        ->and(array_column($payload['navigationGroups'], 'name'))->toBe(['reports', 'main', 'settings'])
        ->and(array_column($payload['navigationGroups'][2]['items'], 'name'))->toBe(['users', 'roles', 'audit'])
        ->and(array_column($payload['navigationItems'], 'name'))->toBe(['dashboard'])
        ->and(array_column($payload['userMenuItems'], 'name'))->toBe(['profile', 'logout'])
        ->and(array_key_exists('middleware', $payload))->toBeFalse()
        ->and(array_key_exists('authMiddleware', $payload))->toBeFalse();
});

it('allows safe relative and explicitly supported navigation URL schemes', function (string $url): void {
    expect(NavigationItem::make('safe')->url($url)->jsonSerialize()['url'])->toBe($url);
})->with([
    '/admin/users',
    'admin/users',
    './users',
    '../users',
    '?tab=users',
    '#users',
    'https://example.com/users',
    'http://example.com/users',
    'mailto:support@example.com',
    'tel:+85212345678',
]);

it('rejects unsafe navigation URLs', function (string $url): void {
    NavigationItem::make('unsafe')->url($url);
})->with([
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'ftp://example.com/file',
    '//evil.example/path',
    '\\\\evil.example\\path',
    '/\\evil.example/path',
    '\\/evil.example/path',
    "https://example.com/\nmalicious",
    '',
    '   ',
])->throws(InvalidArgumentException::class);

it('serializes visibility, active metadata, badges, and safe attributes without dropping hidden items', function (): void {
    $panel = Panel::make('app')->navigationGroups([
        NavigationGroup::make('billing')
            ->visibleWhen('permissions.billing')
            ->extraAttributes(['data-group' => 'billing'])
            ->items([
                NavigationItem::make('invoices')
                    ->icon('receipt')
                    ->url('/app/invoices', newTab: true)
                    ->badge(4)
                    ->visible(false)
                    ->visibleWhen(Condition::truthy('permissions.invoices'))
                    ->activeWhen('route', 'invoices')
                    ->extraAttributes(['aria-label' => 'Invoices', 'data-track' => 'invoices']),
            ]),
    ]);
    $payload = json_decode(json_encode($panel, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $group = $payload['navigationGroups'][0];
    $item = $group['items'][0];

    expect($group['visibleWhen'])->toBe(['path' => 'permissions.billing', 'operator' => 'equals', 'value' => true])
        ->and($item)->toMatchArray([
            'name' => 'invoices',
            'icon' => 'receipt',
            'url' => '/app/invoices',
            'badge' => 4,
            'visible' => false,
            'active' => false,
            'openInNewTab' => true,
        ])->and($item['visibleWhen'])->toBe(['path' => 'permissions.invoices', 'operator' => 'truthy', 'value' => null])
        ->and($item['activeWhen'])->toBe(['path' => 'route', 'operator' => 'equals', 'value' => 'invoices'])
        ->and($item['extraAttributes'])->toBe(['aria-label' => 'Invoices', 'data-track' => 'invoices']);
});

it('registers multiple panels with unique IDs and paths and resolves default and current panels', function (): void {
    $registry = new PanelRegistry;
    $registry->register(Panel::make('app')->path('/'));
    $registry->register(Panel::make('admin')->path('/admin'), default: true);

    expect($registry->default()->id())->toBe('admin')
        ->and($registry->current()->id())->toBe('admin')
        ->and($registry->findByPath('/')->id())->toBe('app')
        ->and(array_map(fn (Panel $panel): string => $panel->id(), $registry->all()))->toBe(['admin', 'app']);

    $registry->setCurrent('app');
    expect($registry->current()->id())->toBe('app');
    $registry->setCurrent(null);
    expect($registry->current()->id())->toBe('admin');
});

it('resolves the active panel from route defaults, route names, and paths', function (): void {
    $registry = new PanelRegistry;
    $registry->register(Panel::make('app')->path('/'));
    $registry->register(Panel::make('admin')->path('/admin'), default: true);

    $request = Request::create('/admin/users', 'GET');
    $route = new Route(['GET'], '/admin/users', static fn (): null => null);
    $route->name('inlay.admin.users.index')->bind($request);
    $request->setRouteResolver(static fn (): Route => $route);

    expect($registry->resolveForRequest($request)?->id())->toBe('admin');
    expect($registry->resolveForRequest(Request::create('/admin/users', 'GET'))?->id())->toBe('admin');

    $standalone = Request::create('/standalone/forms', 'GET');
    $standaloneRegistry = new PanelRegistry;
    $standaloneRegistry->register(Panel::make('admin')->path('/admin'));
    expect($standaloneRegistry->resolveForRequest($standalone))->toBeNull();
});

it('builds an authorization-aware minimal directory for authenticated panel users', function (): void {
    $registry = new PanelRegistry;
    $registry->register(Panel::make('admin')->path('/admin')->brandName('Admin'), default: true);
    $registry->register(Panel::make('billing')->path('/billing'));
    $registry->register(Panel::make('support-console')->path('/support')->brandLogo('/support.svg'));

    $user = new class implements PanelUser
    {
        public function canAccessPanel(Panel $panel): bool
        {
            return $panel->id() !== 'billing';
        }
    };

    expect($registry->directoryFor(null))->toBe([])
        ->and($registry->directoryFor($user))->toBe([
            ['id' => 'admin', 'label' => 'Admin', 'path' => '/admin', 'brandLogo' => null],
            ['id' => 'support-console', 'label' => 'Support Console', 'path' => '/support', 'brandLogo' => '/support.svg'],
        ])
        ->and($registry->directoryFor(new stdClass))->toHaveCount(3);
});

it('registers panels through a Laravel-style panel provider', function (): void {
    $app = new Container;
    $app->singleton(PanelRegistry::class, fn (): PanelRegistry => new PanelRegistry);
    $provider = new class($app) extends PanelProvider
    {
        public function panel(Panel $panel): Panel
        {
            return $panel->path('/admin')->brandName('Admin');
        }

        protected function panelId(): string
        {
            return 'admin';
        }

        protected function isDefaultPanel(): bool
        {
            return true;
        }
    };

    $provider->register();

    expect($app->make(PanelRegistry::class)->default()->jsonSerialize())
        ->id->toBe('admin')
        ->brandName->toBe('Admin');
});

it('stores PHP-first admin composition settings without exposing server internals', function (): void {
    $panel = Panel::make('admin')
        ->loginComponent('auth/sign-in')
        ->dashboardComponent('admin/home')
        ->authGuard('staff')
        ->middleware(['web'])
        ->authMiddleware(['auth:staff'])
        ->resourceMutationMiddleware(['precognitive'])
        ->navigationItem(NavigationItem::make('dashboard'));

    expect($panel->loginComponentName())->toBe('auth/sign-in')
        ->and($panel->dashboardComponentName())->toBe('admin/home')
        ->and($panel->authGuardName())->toBe('staff')
        ->and($panel->middlewareList())->toBe(['web'])
        ->and($panel->authMiddlewareList())->toBe(['auth:staff'])
        ->and($panel->resourceMutationMiddlewareList())->toBe(['precognitive'])
        ->and($panel->getResources())->toBe([])
        ->and(array_key_exists('authGuard', $panel->jsonSerialize()))->toBeFalse();
});

it('accepts collision-safe protected and public plugin route contributions', function (): void {
    $protected = PanelRoute::get('reports.index', 'reports', fn (): string => 'reports')
        ->middleware(['verified']);
    $public = PanelRoute::post('webhooks.receive', 'webhooks/receive', fn (): string => 'ok')
        ->withoutAuthentication();
    $panel = Panel::make('admin')->routes([$protected, $public]);

    expect($panel->getRoutes())->toBe([$protected, $public])
        ->and($protected->method())->toBe('GET')
        ->and($protected->requiresAuthentication())->toBeTrue()
        ->and($protected->middlewareList())->toBe(['verified'])
        ->and($public->requiresAuthentication())->toBeFalse();
});

it('allows a plugin to contribute resources, routes, abilities, and navigation during registration', function (): void {
    $plugin = new class implements Plugin
    {
        public function id(): string
        {
            return 'acme.reports';
        }

        public function register(PluginContext $context): void
        {
            $context->hostAs(Panel::class)
                ->resource(self::class)
                ->route(PanelRoute::get('reports.index', 'reports', self::class))
                ->ability(AbilityDefinition::make('reports.viewAny'), $this->id())
                ->navigationItem(NavigationItem::make('reports')->url('/admin/reports'));
        }

        public function boot(PluginContext $context): void {}
    };
    $panel = Panel::make('admin')->plugin($plugin);

    expect($panel->getResources())->toBe([$plugin::class])
        ->and($panel->getRoutes()[0]->name())->toBe('reports.index')
        ->and($panel->getAbilities()['reports.viewAny']['owner'])->toBe('acme.reports')
        ->and($panel->jsonSerialize()['navigationItems'][0]['name'])->toBe('reports');
});

it('registers and boots panel plugins once in deterministic insertion order', function (): void {
    $events = new ArrayObject;
    $first = panelTestPlugin('acme.first', $events);
    $second = panelTestPlugin('acme.second', $events);

    $panel = Panel::make('admin')->plugins([$first, $second]);

    expect($panel->hasPlugin('acme.first'))->toBeTrue()
        ->and($panel->getPlugin('acme.second'))->toBe($second)
        ->and($panel->getPlugins())->toBe([$first, $second])
        ->and($panel->pluginContext()->hostAs(Panel::class))->toBe($panel)
        ->and($events->getArrayCopy())->toBe([
            'register:admin:acme.first',
            'register:admin:acme.second',
        ]);

    $panel->bootPlugins()->bootPlugins();

    expect($events->getArrayCopy())->toBe([
        'register:admin:acme.first',
        'register:admin:acme.second',
        'boot:admin:acme.first',
        'boot:admin:acme.second',
    ]);
});

it('configures opt-in account settings and its Inertia component', function (): void {
    $panel = Panel::make('admin')->accountSettings()->accountComponent('settings/account');

    expect($panel->hasAccountSettings())->toBeTrue()
        ->and($panel->accountComponentName())->toBe('settings/account');
});

it('rejects duplicate plugin IDs on the same panel', function (): void {
    $events = new ArrayObject;
    Panel::make('admin')->plugins([
        panelTestPlugin('acme.duplicate', $events),
        panelTestPlugin('acme.duplicate', $events),
    ]);
})->throws(InvalidArgumentException::class);

it('rejects duplicate panels, paths, navigation, unsafe attributes, and invalid theme values', function (Closure $configure): void {
    $configure();
})->with([
    'panel ID' => [fn () => Panel::make('Admin Panel')],
    'empty path' => [fn () => Panel::make('admin')->path('')],
    'color key' => [fn () => Panel::make('admin')->colors(['Primary Color' => '#fff'])],
    'empty color' => [fn () => Panel::make('admin')->colors(['primary' => ''])],
    'theme value' => [fn () => Panel::make('admin')->theme(['radius' => []])],
    'unsafe attribute' => [fn () => NavigationItem::make('users')->extraAttributes(['onclick' => 'alert(1)'])],
    'duplicate item' => [fn () => Panel::make('admin')->navigationItems([NavigationItem::make('users'), NavigationItem::make('users')])],
    'duplicate route name' => [fn () => Panel::make('admin')->routes([
        PanelRoute::get('reports', 'reports', fn (): string => 'one'),
        PanelRoute::get('reports', 'other-reports', fn (): string => 'two'),
    ])],
    'duplicate route signature' => [fn () => Panel::make('admin')->routes([
        PanelRoute::get('reports', 'reports', fn (): string => 'one'),
        PanelRoute::get('other-reports', 'reports', fn (): string => 'two'),
    ])],
    'duplicate ability' => [fn () => Panel::make('admin')->abilities([
        AbilityDefinition::make('reports.viewAny'),
        AbilityDefinition::make('reports.viewAny'),
    ])],
    'duplicate grouped item' => [fn () => Panel::make('admin')->navigationGroups([
        NavigationGroup::make('one')->items([NavigationItem::make('users')]),
        NavigationGroup::make('two')->items([NavigationItem::make('users')]),
    ])->jsonSerialize()],
    'duplicate panel ID' => [function (): void {
        $registry = new PanelRegistry;
        $registry->register(Panel::make('admin'));
        $registry->register(Panel::make('admin')->path('/other'));
    }],
    'duplicate panel path' => [function (): void {
        $registry = new PanelRegistry;
        $registry->register(Panel::make('admin')->path('/shared'));
        $registry->register(Panel::make('app')->path('/shared'));
    }],
    'duplicate default' => [function (): void {
        $registry = new PanelRegistry;
        $registry->register(Panel::make('admin'), default: true);
        $registry->register(Panel::make('app'), default: true);
    }],
])->throws(InvalidArgumentException::class);

final class PanelTenantTeam extends Model
{
    protected $table = 'panel_tenant_teams';

    public $timestamps = false;

    protected $guarded = [];
}

final class PanelTenantUser implements HasTenants
{
    /** @param list<PanelTenantTeam> $teams */
    public function __construct(private array $teams) {}

    public function inlayTenants(): iterable
    {
        return $this->teams;
    }
}

it('publishes the current tenant and the ones the visitor may switch to', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('panel_tenant_teams', function ($table): void {
        $table->id();
        $table->string('slug');
        $table->string('name');
    });

    $acme = PanelTenantTeam::query()->create(['slug' => 'acme', 'name' => 'Acme']);
    $globex = PanelTenantTeam::query()->create(['slug' => 'globex', 'name' => 'Globex']);

    Container::setInstance($container = new Container);
    $request = Request::create('/acme/admin', 'GET');
    $request->setUserResolver(fn (): PanelTenantUser => new PanelTenantUser([$acme, $globex]));
    $container->instance('request', $request);
    Tenancy::resolve()->set($acme);

    try {
        $panel = Panel::make('admin')->path('/admin')->tenant(PanelTenantTeam::class, 'team', 'slug');
        $payload = json_decode(json_encode($panel->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        expect($payload['tenant'])->toBe([
            'parameter' => 'team',
            'current' => ['key' => 'acme', 'label' => 'Acme', 'url' => '/acme/admin'],
            'options' => [
                ['key' => 'acme', 'label' => 'Acme', 'url' => '/acme/admin'],
                ['key' => 'globex', 'label' => 'Globex', 'url' => '/globex/admin'],
            ],
        ])
            // A panel without tenancy stays exactly as it was.
            ->and(json_decode(json_encode(Panel::make('plain')->path('/plain')->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['tenant'])->toBeNull()
            ->and(fn () => Panel::make('bad')->tenant(PanelTenantTeam::class, 'not a name'))
            ->toThrow(InvalidArgumentException::class, 'must be a valid identifier')
            ->and(fn () => Panel::make('bad')->tenant(stdClass::class))
            ->toThrow(InvalidArgumentException::class, 'must be an Eloquent model');
    } finally {
        Tenancy::resolve()->forget();
    }
});

it('prefixes every protected panel route with the tenant', function (): void {
    $container = new Container;
    $container->instance(AbilityRegistry::class, new AbilityRegistry);
    $router = new Router(new Dispatcher, $container);

    $panel = Panel::make('admin')->path('/admin')->tenant(PanelTenantTeam::class, 'team', 'slug');
    (new PanelRegistrar($router, new PanelRegistry, $container->make(AbilityRegistry::class), $container))->register($panel);

    $routes = [];
    foreach ($router->getRoutes() as $route) {
        $routes[(string) $route->getName()] = $route;
    }

    expect($routes['inlay.admin.dashboard']->uri())->toBe('{team}/admin')
        ->and($routes['inlay.admin.dashboard']->gatherMiddleware())->toContain(ResolveTenant::class)
        ->and($routes['inlay.admin.dashboard']->defaults['inlayTenantRouteKey'])->toBe('slug')
        // Signing in happens before a tenant exists, so login stays outside it.
        ->and($routes['inlay.admin.login']->uri())->toBe('admin/login')
        ->and($routes['inlay.admin.login']->gatherMiddleware())->not->toContain(ResolveTenant::class);
});

it('hides an ability-gated navigation item, and shows it once the ability is granted', function (): void {
    $panel = fn (): Panel => Panel::make('admin')->navigationItems([
        NavigationItem::make('dashboard')->url('/admin'),
        NavigationItem::make('cms-content')->url('/admin/cms/tree')->ability('cms.tree.view'),
    ]);

    $names = fn (Panel $built): array => array_map(
        static fn (array $item): string => $item['name'],
        $built->jsonSerialize()['navigationItems'],
    );

    // Without an authorization manager there is nothing to check against, so a
    // gated item stays visible rather than vanishing from every panel.
    $container = new Container;
    LaravelContainer::setInstance($container);

    expect($names($panel()))->toBe(['cms-content', 'dashboard']);

    // Denied: the gated item is gone, the ungated one is untouched.
    $visitor = (object) ['allowed' => false];
    $gate = new Gate($container, fn () => $visitor);
    $gate->define('cms.tree.view', fn (object $user): bool => $user->allowed);
    $container->instance(AuthorizationManager::class, new AuthorizationManager($gate, new AbilityRegistry));
    $container->instance('auth', new class($visitor)
    {
        public function __construct(private object $user) {}

        public function user(): object
        {
            return $this->user;
        }
    });

    expect($names($panel()))->toBe(['dashboard']);

    // Granted: it comes back. Without this direction a gate that hid everything
    // would look exactly like a gate that worked.
    $visitor->allowed = true;

    expect($names($panel()))->toBe(['cms-content', 'dashboard']);

    LaravelContainer::setInstance(null);
});
