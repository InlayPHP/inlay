<?php

declare(strict_types=1);

namespace Inlay;

use Illuminate\Container\Container as LaravelContainer;
use Inlay\Authorization\AuthorizationManager;
use Inlay\Auth\LoginStep;
use Illuminate\Database\Eloquent\Model;
use Inlay\Authorization\AbilityDefinition;
use Inlay\Core\AssetRegistry;
use Inlay\Core\Contracts\Plugin;
use Inlay\Core\ExtensionRegistry;
use Inlay\Core\Inlay as InlayCore;
use Inlay\Core\PluginContext;
use Inlay\Core\PluginManager;
use Inlay\Core\RenderHookRegistry;
use Inlay\Resources\Contracts\HasTenants;
use Inlay\Resources\Tenancy;
use Inlay\Theme\Theme;
use Inlay\Widgets\Contracts\ProvidesWidgets;
use Inlay\Widgets\WidgetDiscovery;
use Inlay\Widgets\Widget;
use InvalidArgumentException;
use JsonSerializable;

final class Panel implements JsonSerializable
{
    private string $path;

    private ?string $brandName = null;

    private ?string $brandLogo = null;

    /** @var array<string, string> */
    private array $colors = [];

    /** @var array<string, scalar|null> */
    private array $theme = [];

    /** @var array<string, scalar|null> */
    private array $darkTheme = [];

    private string $themeName = 'default';

    private string $navigationMode = 'sidebar';

    /** @var array{model: class-string, parameter: string, routeKey: string}|null */
    private ?array $tenant = null;

    private bool $collapsible = false;

    private bool $breadcrumbs = true;

    private bool $topbar = true;

    /** @var list<string> */
    private array $middleware = [];

    /** @var list<string> */
    private array $authMiddleware = [];

    /** @var list<string> */
    private array $resourceMutationMiddleware = [];

    /** @var list<NavigationGroup> */
    private array $navigationGroups = [];

    /** @var list<NavigationItem> */
    private array $navigationItems = [];

    /** @var list<NavigationItem> */
    private array $userMenuItems = [];

    private bool $spa = false;

    private string $renderComponent = 'PanelLayout';

    /** Enable the resource search endpoint and top-bar contract. */
    private bool $globalSearch = true;

    private ?string $globalSearchEndpoint = null;

    /** @var 'header-start'|'header-end'|'sidebar'|'sidebar-footer' */
    private string $globalSearchPosition = 'header-end';

    /** @var list<class-string> */
    private array $resources = [];

    /** @var list<PanelRoute> */
    private array $routes = [];

    /** @var array<string, array{definition: AbilityDefinition, owner: string}> */
    private array $abilities = [];

    /** @var list<Widget|ProvidesWidgets|class-string<ProvidesWidgets>> */
    private array $widgets = [];

    /** @var list<LoginStep|class-string<LoginStep>> */
    private array $loginSteps = [];

    private string $loginComponent = 'auth/login';

    private string $dashboardComponent = 'admin/dashboard';

    private bool $accountSettings = false;

    private string $accountComponent = 'inlay/account-settings';

    private string $authGuard = 'web';

    private PluginManager $pluginManager;

    private function __construct(private readonly string $id)
    {
        $this->path = '/'.$id;
        $this->applyTheme(Theme::default());
        $this->pluginManager = new PluginManager(
            InlayCore::VERSION,
            new PluginContext(
                $this,
                new ExtensionRegistry,
                new AssetRegistry,
                new RenderHookRegistry,
            ),
        );
    }

    public static function make(string $id): self
    {
        $id = trim($id);

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) {
            throw new InvalidArgumentException('A panel ID must contain only lowercase letters, numbers, hyphens, and underscores.');
        }

        return new self($id);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function path(string $path): self
    {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException('A panel path cannot be empty.');
        }

        $this->path = $path === '/' ? '/' : '/'.trim($path, '/');

        return $this;
    }

    public function pathValue(): string
    {
        return $this->path;
    }

    /**
     * Return the safe, renderer-neutral identity used by a panel directory.
     *
     * The complete panel payload contains navigation and plugin metadata and is
     * intended for the active panel. A directory only needs enough information
     * to let an already-authenticated visitor choose another panel.
     *
     * @return array{id: string, label: string, path: string, brandLogo: string|null}
     */
    public function directoryEntry(): array
    {
        $label = $this->brandName;
        if ($label === null) {
            $label = preg_replace('/[-_]+/', ' ', $this->id) ?: $this->id;
            $label = ucwords($label);
        }

        return [
            'id' => $this->id,
            'label' => $label,
            'path' => $this->path,
            'brandLogo' => $this->brandLogo,
        ];
    }

    public function brandName(string $name): self
    {
        $this->brandName = $name;

        return $this;
    }

    public function brandLogo(string $logo): self
    {
        $this->brandLogo = $logo;

        return $this;
    }

    /** @param array<string, string> $colors */
    public function colors(array $colors): self
    {
        self::assertTokens($colors, true);
        $this->colors = [...$this->colors, ...$colors];

        return $this;
    }

    /** @param array<string, scalar|null>|Theme $theme */
    public function theme(array|Theme $theme): self
    {
        if ($theme instanceof Theme) {
            $this->applyTheme($theme);

            return $this;
        }

        self::assertTokens($theme);
        $this->theme = [...$this->theme, ...$theme];

        return $this;
    }

    /** @param array<string, scalar|null> $tokens */
    public function darkTheme(array $tokens): self
    {
        self::assertTokens($tokens);
        $this->darkTheme = [...$this->darkTheme, ...$tokens];

        return $this;
    }

    public function sidebarNavigation(): self
    {
        $this->navigationMode = 'sidebar';

        return $this;
    }

    public function topNavigation(): self
    {
        $this->navigationMode = 'top';

        return $this;
    }

    public function collapsible(bool $collapsible = true): self
    {
        $this->collapsible = $collapsible;

        return $this;
    }

    public function breadcrumbs(bool $enabled = true): self
    {
        $this->breadcrumbs = $enabled;

        return $this;
    }

    public function topbar(bool $enabled = true): self
    {
        $this->topbar = $enabled;

        return $this;
    }

    /** @param list<class-string|string> $middleware */
    public function middleware(array $middleware): self
    {
        $this->middleware = self::validatedMiddleware($middleware, 'panel');

        return $this;
    }

    /** @param list<class-string|string> $middleware */
    public function authMiddleware(array $middleware): self
    {
        $this->authMiddleware = self::validatedMiddleware($middleware, 'authentication');

        return $this;
    }

    /** @param list<class-string|string> $middleware */
    public function resourceMutationMiddleware(array $middleware): self
    {
        $this->resourceMutationMiddleware = self::validatedMiddleware($middleware, 'resource mutation');

        return $this;
    }

    /** @param list<NavigationGroup> $groups */
    public function navigationGroups(array $groups): self
    {
        self::assertUniqueObjects($groups, NavigationGroup::class, 'navigation group');
        $this->navigationGroups = array_values($groups);

        return $this;
    }

    /** @param list<NavigationItem> $items */
    public function navigationItems(array $items): self
    {
        self::assertUniqueObjects($items, NavigationItem::class, 'navigation item');
        $this->navigationItems = array_values($items);

        return $this;
    }

    /** @param list<NavigationItem> $items */
    public function userMenuItems(array $items): self
    {
        self::assertUniqueObjects($items, NavigationItem::class, 'user menu item');
        $this->userMenuItems = array_values($items);

        return $this;
    }

    public function userMenuItem(NavigationItem $item): self
    {
        foreach ($this->userMenuItems as $existing) {
            if ($existing->name() === $item->name()) {
                throw new InvalidArgumentException("Duplicate user menu item [{$item->name()}].");
            }
        }

        $this->userMenuItems[] = $item;

        return $this;
    }

    public function spa(bool $enabled = true): self
    {
        $this->spa = $enabled;

        return $this;
    }

    public function renderComponent(string $component): self
    {
        $component = trim($component);

        if ($component === '') {
            throw new InvalidArgumentException('A panel render component cannot be empty.');
        }

        $this->renderComponent = $component;

        return $this;
    }

    /**
     * Enable or disable the resource global search surface for this panel.
     *
     * The endpoint is attached when the panel registrar sees its resources;
     * keeping the switch on the panel means a second panel can opt out without
     * changing a Resource's searchable attributes.
     */
    public function globalSearch(bool $enabled = true): self
    {
        $this->globalSearch = $enabled;

        if (! $enabled) {
            $this->globalSearchEndpoint = null;
        }

        return $this;
    }

    public function globalSearchEnabled(): bool
    {
        return $this->globalSearch;
    }

    /**
     * Choose where the renderer places the compact resource search control.
     *
     * Header-end is the default and keeps the search near the account actions.
     * Sidebar-footer is useful for an always-available search at the bottom of
     * a left navigation rail.
     *
     * @param  'header-start'|'header-end'|'sidebar'|'sidebar-footer'  $position
     */
    public function globalSearchPosition(string $position): self
    {
        $position = trim($position);

        if (! in_array($position, ['header-start', 'header-end', 'sidebar', 'sidebar-footer'], true)) {
            throw new InvalidArgumentException('A global search position must be header-start, header-end, sidebar, or sidebar-footer.');
        }

        $this->globalSearchPosition = $position;

        return $this;
    }

    /** @return 'header-start'|'header-end'|'sidebar'|'sidebar-footer' */
    public function globalSearchPositionValue(): string
    {
        return $this->globalSearchPosition;
    }

    /** @internal Set by PanelRegistrar after the protected route exists. */
    public function globalSearchEndpoint(?string $endpoint): self
    {
        $this->globalSearchEndpoint = $endpoint;

        return $this;
    }

    /**
     * Scope this panel to a tenant.
     *
     * Every route gains the tenant segment, and the payload carries the
     * current tenant plus the ones the visitor may switch to.
     *
     * @param  class-string  $model
     */
    public function tenant(string $model, string $parameter = 'tenant', ?string $routeKey = null): self
    {
        if (! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException('A panel tenant must be an Eloquent model.');
        }
        $parameter = trim($parameter, '{}');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $parameter) !== 1) {
            throw new InvalidArgumentException('A panel tenant route parameter must be a valid identifier.');
        }

        $this->tenant = [
            'model' => $model,
            'parameter' => $parameter,
            'routeKey' => $routeKey ?? (new $model)->getRouteKeyName(),
        ];

        return $this;
    }

    /** @return array{model: class-string, parameter: string, routeKey: string}|null */
    public function tenantConfiguration(): ?array
    {
        return $this->tenant;
    }

    /** @param list<class-string> $resources */
    public function resources(array $resources): self
    {
        foreach ($resources as $resource) {
            if (! is_string($resource) || trim($resource) === '' || ! class_exists($resource)) {
                throw new InvalidArgumentException('Panel resources must be existing class names.');
            }
        }

        $this->resources = array_values(array_unique($resources));

        return $this;
    }

    /** @param class-string $resource */
    public function resource(string $resource): self
    {
        if (trim($resource) === '' || ! class_exists($resource)) {
            throw new InvalidArgumentException('A panel resource must be an existing class name.');
        }

        if (in_array($resource, $this->resources, true)) {
            throw new InvalidArgumentException("Duplicate panel resource [{$resource}].");
        }

        $this->resources[] = $resource;

        return $this;
    }

    /** @return list<class-string> */
    public function getResources(): array
    {
        return $this->resources;
    }

    /** @param list<PanelRoute> $routes */
    public function routes(array $routes): self
    {
        foreach ($routes as $route) {
            if (! $route instanceof PanelRoute) {
                throw new InvalidArgumentException('Panel routes must be PanelRoute instances.');
            }

            $this->route($route);
        }

        return $this;
    }

    public function route(PanelRoute $route): self
    {
        foreach ($this->routes as $existing) {
            if ($existing->name() === $route->name()) {
                throw new InvalidArgumentException("Duplicate panel route name [{$route->name()}].");
            }

            if ($existing->method() === $route->method() && $existing->uri() === $route->uri()) {
                throw new InvalidArgumentException("Duplicate panel route [{$route->method()} {$route->uri()}].");
            }
        }

        $this->routes[] = $route;

        return $this;
    }

    /** @return list<PanelRoute> */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function ability(AbilityDefinition $ability, string $owner = 'panel'): self
    {
        $owner = trim($owner);
        if ($owner === '') {
            throw new InvalidArgumentException('A panel ability owner cannot be empty.');
        }

        $name = $ability->name();
        if (isset($this->abilities[$name])) {
            throw new InvalidArgumentException("Duplicate panel ability [{$name}].");
        }

        $this->abilities[$name] = ['definition' => $ability, 'owner' => $owner];

        return $this;
    }

    /** @param iterable<AbilityDefinition> $abilities */
    public function abilities(iterable $abilities, string $owner = 'panel'): self
    {
        foreach ($abilities as $ability) {
            if (! $ability instanceof AbilityDefinition) {
                throw new InvalidArgumentException('Panel abilities must be AbilityDefinition instances.');
            }

            $this->ability($ability, $owner);
        }

        return $this;
    }

    /** @return array<string, array{definition: AbilityDefinition, owner: string}> */
    public function getAbilities(): array
    {
        ksort($this->abilities);

        return $this->abilities;
    }

    /** @param iterable<Widget|ProvidesWidgets|class-string<ProvidesWidgets>> $widgets */
    public function widgets(iterable $widgets): self
    {
        foreach ($widgets as $widget) {
            $this->widget($widget);
        }

        return $this;
    }

    /**
     * Discover request-aware widget providers in an application namespace.
     *
     * Discovery caches only provider class names for the current process;
     * providers are still resolved for each dashboard request.
     *
     * @param string|array<int, string> $directories
     */
    public function discoverWidgets(string|array $directories, string $namespace): self
    {
        foreach ((new WidgetDiscovery)->discover($directories, $namespace) as $provider) {
            $this->widget($provider);
        }

        return $this;
    }

    /** @param Widget|ProvidesWidgets|class-string<ProvidesWidgets> $widget */
    public function widget(Widget|ProvidesWidgets|string $widget): self
    {
        if (is_string($widget) && (! class_exists($widget) || ! is_subclass_of($widget, ProvidesWidgets::class))) {
            throw new InvalidArgumentException('A widget provider class must implement ProvidesWidgets.');
        }
        $this->widgets[] = $widget;

        return $this;
    }

    /**
     * Add a post-credential login step, such as a two-factor challenge.
     *
     * Steps are intentionally panel-scoped so a plugin can be enabled for one
     * panel without changing another panel's guard or redirect behavior.
     *
     * @param LoginStep|class-string<LoginStep> $step
     */
    public function loginStep(LoginStep|string $step): self
    {
        if (is_string($step) && (! class_exists($step) || ! is_subclass_of($step, LoginStep::class))) {
            throw new InvalidArgumentException('A panel login step class must implement '.LoginStep::class.'.');
        }

        $this->loginSteps[] = $step;

        return $this;
    }

    /** @return list<LoginStep|class-string<LoginStep>> */
    public function loginSteps(): array
    {
        return $this->loginSteps;
    }

    /** @return list<Widget|ProvidesWidgets|class-string<ProvidesWidgets>> */
    public function getWidgets(): array
    {
        return $this->widgets;
    }

    public function loginComponent(string $component): self
    {
        $this->loginComponent = self::nonEmpty($component, 'login component');

        return $this;
    }

    public function loginComponentName(): string
    {
        return $this->loginComponent;
    }

    public function dashboardComponent(string $component): self
    {
        $this->dashboardComponent = self::nonEmpty($component, 'dashboard component');

        return $this;
    }

    public function dashboardComponentName(): string
    {
        return $this->dashboardComponent;
    }

    public function accountSettings(bool $enabled = true): self
    {
        $this->accountSettings = $enabled;

        return $this;
    }

    public function hasAccountSettings(): bool
    {
        return $this->accountSettings;
    }

    public function accountComponent(string $component): self
    {
        $this->accountComponent = self::nonEmpty($component, 'account component');

        return $this;
    }

    public function accountComponentName(): string
    {
        return $this->accountComponent;
    }

    public function authGuard(string $guard): self
    {
        $this->authGuard = self::nonEmpty($guard, 'authentication guard');

        return $this;
    }

    public function authGuardName(): string
    {
        return $this->authGuard;
    }

    /** @return list<string> */
    public function middlewareList(): array
    {
        return $this->middleware;
    }

    /** @return list<string> */
    public function authMiddlewareList(): array
    {
        return $this->authMiddleware;
    }

    /** @return list<string> */
    public function resourceMutationMiddlewareList(): array
    {
        return $this->resourceMutationMiddleware;
    }

    public function navigationItem(NavigationItem $item): self
    {
        foreach ($this->navigationItems as $existing) {
            if ($existing->name() === $item->name()) {
                throw new InvalidArgumentException("Duplicate navigation item [{$item->name()}].");
            }
        }

        $this->navigationItems[] = $item;

        return $this;
    }

    public function plugin(Plugin $plugin): self
    {
        $this->pluginManager->register($plugin);

        return $this;
    }

    /** @param iterable<Plugin> $plugins */
    public function plugins(iterable $plugins): self
    {
        foreach ($plugins as $plugin) {
            $this->plugin($plugin);
        }

        return $this;
    }

    public function hasPlugin(string $id): bool
    {
        return $this->pluginManager->has($id);
    }

    public function getPlugin(string $id): Plugin
    {
        return $this->pluginManager->plugin($id);
    }

    /** @return list<Plugin> */
    public function getPlugins(): array
    {
        return $this->pluginManager->plugins();
    }

    public function pluginContext(): PluginContext
    {
        return $this->pluginManager->context();
    }

    public function bootPlugins(): self
    {
        $this->pluginManager->boot();

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        [$groups, $items] = $this->normalizedNavigation();

        // Materialize nested navigation records at the contract boundary. A
        // response encoder would recurse through JsonSerializable objects, but
        // Inertia's PHP-side assertions (and direct consumers) receive the
        // value returned by jsonSerialize() before that encoding step.
        $groups = array_map(static function (array $group): array {
            $group['items'] = array_map(
                static fn (NavigationItem $item): array => $item->jsonSerialize(),
                $group['items'] ?? [],
            );

            return $group;
        }, $groups);

        $items = array_map(
            static fn (NavigationItem $item): array => $item->jsonSerialize(),
            $items,
        );

        $userMenuItems = array_map(
            static fn (NavigationItem $item): array => $item->jsonSerialize(),
            self::sortedItems($this->userMenuItems),
        );

        return [
            'contract' => 'inlay.panels.v1',
            'type' => 'panel',
            'id' => $this->id,
            'path' => $this->path,
            'brandName' => $this->brandName,
            'brandLogo' => $this->brandLogo,
            'colors' => (object) $this->colors,
            'theme' => (object) $this->theme,
            'darkTheme' => (object) $this->darkTheme,
            'themeName' => $this->themeName,
            'navigationMode' => $this->navigationMode,
            'collapsible' => $this->collapsible,
            'breadcrumbs' => $this->breadcrumbs,
            'topbar' => $this->topbar,
            'navigationGroups' => $groups,
            'navigationItems' => $items,
            'userMenuItems' => $userMenuItems,
            'spa' => $this->spa,
            'renderComponent' => $this->renderComponent,
            'globalSearch' => $this->globalSearchEndpoint === null ? null : [
                'endpoint' => $this->globalSearchEndpoint,
                'minChars' => 2,
                'placeholder' => 'Search resources…',
                'position' => $this->globalSearchPosition,
            ],
            'tenant' => $this->serializedTenant(),
        ];
    }

    /**
     * The tenant this request belongs to and the ones the visitor may switch
     * to, resolved per request rather than stored on the panel.
     *
     * @return array{parameter: string, current: array{key: string, label: string, url: string}|null, options: list<array{key: string, label: string, url: string}>}|null
     */
    private function serializedTenant(): ?array
    {
        if ($this->tenant === null) {
            return null;
        }

        // Resources is an optional dependency, so tenancy is read only when it
        // is actually installed.
        $current = class_exists(Tenancy::class) ? Tenancy::resolve()->current() : null;
        $user = LaravelContainer::getInstance()->bound('request')
            ? LaravelContainer::getInstance()->make('request')->user()
            : null;

        $options = [];
        if (interface_exists(HasTenants::class) && $user instanceof HasTenants) {
            foreach ($user->inlayTenants() as $tenant) {
                if ($tenant instanceof Model) {
                    $options[] = $this->serializedTenantOption($tenant);
                }
            }
        }

        return [
            'parameter' => $this->tenant['parameter'],
            'current' => $current instanceof Model ? $this->serializedTenantOption($current) : null,
            'options' => $options,
        ];
    }

    /** @return array{key: string, label: string, url: string} */
    private function serializedTenantOption(Model $tenant): array
    {
        $key = (string) $tenant->getAttribute($this->tenant['routeKey'] ?? 'id');

        return [
            'key' => $key,
            'label' => $tenant instanceof HasTenants || ! method_exists($tenant, 'inlayTenantLabel')
                ? (string) ($tenant->getAttribute('name') ?? $tenant->getAttribute('title') ?? $key)
                : (string) $tenant->inlayTenantLabel(),
            'url' => '/'.trim($key.'/'.trim($this->path, '/'), '/'),
        ];
    }

    /** @param array<string, mixed> $tokens */
    private static function assertTokens(array $tokens, bool $colors = false): void
    {
        foreach ($tokens as $name => $value) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
                throw new InvalidArgumentException("Invalid panel theme token [{$name}].");
            }

            if ($colors && (! is_string($value) || trim($value) === '')) {
                throw new InvalidArgumentException("Panel color [{$name}] must be a non-empty string.");
            }

            if (! $colors && ! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Panel theme token [{$name}] must be scalar or null.");
            }
        }
    }

    private function applyTheme(Theme $theme): void
    {
        self::assertTokens($theme->light());
        self::assertTokens($theme->dark());
        $this->themeName = $theme->name();
        $this->theme = $theme->light();
        $this->darkTheme = $theme->dark();
    }

    private static function nonEmpty(string $value, string $name): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("Panel {$name} cannot be empty.");
        }

        return $value;
    }

    /** @param list<mixed> $middleware @return list<string> */
    private static function validatedMiddleware(array $middleware, string $kind): array
    {
        foreach ($middleware as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw new InvalidArgumentException("Every {$kind} middleware entry must be a non-empty class or alias.");
            }
        }

        return array_values(array_unique($middleware));
    }

    /** @param list<mixed> $objects @param class-string $class */
    private static function assertUniqueObjects(array $objects, string $class, string $kind): void
    {
        $names = [];

        foreach ($objects as $object) {
            if (! $object instanceof $class) {
                throw new InvalidArgumentException(ucfirst($kind)." entries must be {$class} instances.");
            }

            if (isset($names[$object->name()])) {
                throw new InvalidArgumentException("Duplicate {$kind} [{$object->name()}].");
            }

            $names[$object->name()] = true;
        }
    }

    /** @param list<NavigationGroup> $groups @return list<NavigationGroup> */
    private static function sortedGroups(array $groups): array
    {
        usort($groups, fn (NavigationGroup $left, NavigationGroup $right): int => [
            $left->sortOrder(), strtolower($left->labelText()), $left->name(),
        ] <=> [
            $right->sortOrder(), strtolower($right->labelText()), $right->name(),
        ]);

        return $groups;
    }

    /** @param list<NavigationItem> $items @return list<NavigationItem> */
    private static function sortedItems(array $items): array
    {
        usort($items, fn (NavigationItem $left, NavigationItem $right): int => [
            $left->sortOrder(), strtolower($left->labelText()), $left->name(),
        ] <=> [
            $right->sortOrder(), strtolower($right->labelText()), $right->name(),
        ]);

        return $items;
    }

    /** @return array{list<array<string, mixed>>, list<NavigationItem>} */
    /**
     * Whether the current visitor may see a navigation item.
     *
     * Items without a declared ability are always listed; the check is opt-in
     * so existing panels keep their navigation unchanged.
     */
    private function navigationItemVisible(NavigationItem $item): bool
    {
        $ability = $item->requiredAbility();

        if ($ability === null) {
            return true;
        }

        $container = LaravelContainer::getInstance();

        // Authorization is an optional dependency; without it there is nothing
        // to check against, so the item stays visible rather than vanishing.
        if (! $container->bound(AuthorizationManager::class)) {
            return true;
        }

        $user = $container->bound('auth') ? $container->make('auth')->user() : null;

        return $container->make(AuthorizationManager::class)->allows($user, $ability);
    }

    private function normalizedNavigation(): array
    {
        $groups = [];
        $groupIndexes = [];
        $seenItems = [];
        $emptiedByAuthorization = [];

        foreach (self::sortedGroups($this->navigationGroups) as $group) {
            $payload = $group->jsonSerialize();
            $groupIndexes[$group->name()] = count($groups);

            foreach ($group->itemsList() as $item) {
                if (isset($seenItems[$item->name()])) {
                    throw new InvalidArgumentException("Duplicate navigation item [{$item->name()}].");
                }

                $seenItems[$item->name()] = true;
            }

            $declared = $payload['items'] ?? [];
            $payload['items'] = array_values(array_filter(
                $declared,
                fn ($item): bool => ! $item instanceof NavigationItem || $this->navigationItemVisible($item),
            ));

            // Remember whether this group lost every item to the ability filter,
            // as opposed to having been declared empty on purpose.
            $emptiedByAuthorization[count($groups)] = $declared !== [] && $payload['items'] === [];

            $groups[] = $payload;
        }

        $ungrouped = [];

        foreach (self::sortedItems($this->navigationItems) as $item) {
            if (isset($seenItems[$item->name()])) {
                throw new InvalidArgumentException("Duplicate navigation item [{$item->name()}].");
            }

            $seenItems[$item->name()] = true;

            if (! $this->navigationItemVisible($item)) {
                continue;
            }

            $group = $item->groupName();

            if ($group === null) {
                $ungrouped[] = $item;

                continue;
            }

            if (! isset($groupIndexes[$group])) {
                $groupIndexes[$group] = count($groups);
                $groups[] = NavigationGroup::make($group)->jsonSerialize();
            }

            $groups[$groupIndexes[$group]]['items'][] = $item;
        }

        foreach ($groups as &$group) {
            $group['items'] = self::sortedItems($group['items']);
        }
        unset($group);

        // A group whose every item was filtered out would otherwise render as an
        // empty heading. A group the author declared empty is left alone.
        $groups = array_values(array_filter(
            $groups,
            static fn (array $group, int $index): bool => ($group['items'] ?? []) !== []
                || ! ($emptiedByAuthorization[$index] ?? false),
            ARRAY_FILTER_USE_BOTH,
        ));

        usort($groups, fn (array $left, array $right): int => [
            $left['sort'], strtolower($left['label']), $left['name'],
        ] <=> [
            $right['sort'], strtolower($right['label']), $right['name'],
        ]);

        return [$groups, $ungrouped];
    }
}
