<?php

declare(strict_types=1);

use Inlay\Core\Asset;
use Inlay\Core\AssetRegistry;
use Inlay\Core\Contracts\Plugin;
use Inlay\Core\ContractVersion;
use Inlay\Core\ExtensionManifest;
use Inlay\Core\ExtensionRegistry;
use Inlay\Core\Inlay;
use Inlay\Core\PluginContext;
use Inlay\Core\PluginManager;
use Inlay\Core\RenderHook;
use Inlay\Core\RenderHookRegistry;

function coreContext(?object $host = null): PluginContext
{
    return new PluginContext(
        $host ?? new stdClass,
        new ExtensionRegistry,
        new AssetRegistry,
        new RenderHookRegistry,
    );
}

it('parses versions and validates common semantic constraints', function (): void {
    $version = ContractVersion::from('0.2.3');

    expect((string) $version)->toBe('0.2.3')
        ->and($version->satisfies('^0.2.0'))->toBeTrue()
        ->and($version->satisfies('~0.2.2'))->toBeTrue()
        ->and($version->satisfies('>=0.2.0 <0.3.0'))->toBeTrue()
        ->and($version->satisfies('>=0.2'))->toBeTrue()
        ->and($version->satisfies('0.2.*'))->toBeTrue()
        ->and($version->satisfies('^0.3.0'))->toBeFalse();

    expect(fn () => ContractVersion::from('v2'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $version->satisfies('banana'))->toThrow(InvalidArgumentException::class);
});

it('describes extensions and rejects incompatible core contracts', function (): void {
    $manifest = ExtensionManifest::make(
        id: 'acme/maps',
        version: '1.4.0',
        requiresCore: '^0.2.0',
        name: 'Maps',
        capabilities: ['forms.fields', 'assets'],
    );

    expect($manifest->jsonSerialize())->toBe([
        'id' => 'acme/maps',
        'name' => 'Maps',
        'version' => '1.4.0',
        'requiresCore' => '^0.2.0',
        'capabilities' => ['forms.fields', 'assets'],
    ]);

    $manifest->assertCompatibleWith(ContractVersion::from('0.2.9'));

    expect(fn () => $manifest->assertCompatibleWith(ContractVersion::from('0.3.0')))
        ->toThrow(InvalidArgumentException::class, 'requires Inlay core');
});

it('keeps extensions typed and reports ownership collisions', function (): void {
    $registry = (new ExtensionRegistry)->define('handlers', Stringable::class);
    $first = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'first';
        }
    };

    $registry->register('handlers', 'map', $first, 'acme/maps');

    expect($registry->get('handlers', 'map'))->toBe($first)
        ->and($registry->owner('handlers', 'map'))->toBe('acme/maps')
        ->and(array_keys($registry->all('handlers')))->toBe(['map'])
        ->and(fn () => $registry->register('handlers', 'map', $first, 'other/plugin'))
        ->toThrow(InvalidArgumentException::class, 'already registered by [acme/maps]')
        ->and(fn () => $registry->register('handlers', 'bad', new stdClass, 'acme/maps'))
        ->toThrow(InvalidArgumentException::class, Stringable::class);
});

it('registers collision-safe assets and serializes their frontend contract', function (): void {
    $registry = new AssetRegistry;
    $asset = Asset::script('maps-js', '/vendor/maps.js', lazy: true, attributes: ['defer' => true, 'nonce' => 'abc']);
    $namedAsset = new Asset(id: 'named-css', source: '/named.css', kind: Asset::STYLE);
    $registry->register($asset, 'acme/maps');

    expect($registry->get('maps-js'))->toBe($asset)
        ->and($registry->owner('maps-js'))->toBe('acme/maps')
        ->and($asset->kind)->toBe(Asset::SCRIPT)
        ->and($asset->type)->toBe(Asset::SCRIPT)
        ->and($namedAsset->kind)->toBe(Asset::STYLE)
        ->and($registry->all(Asset::SCRIPT))->toBe([$asset])
        ->and($registry->all(Asset::STYLE))->toBe([])
        ->and($asset->jsonSerialize())->toBe([
            'id' => 'maps-js',
            'source' => '/vendor/maps.js',
            'kind' => 'script',
            'lazy' => true,
            'attributes' => ['defer' => true, 'nonce' => 'abc'],
        ])
        ->and(fn () => $registry->register($asset, 'other/plugin'))
        ->toThrow(InvalidArgumentException::class, 'already registered by [acme/maps]');
});

it('rejects unsafe asset attribute names and non-wire values', function (array $attributes, string $message): void {
    expect(fn () => Asset::script('unsafe', '/unsafe.js', attributes: $attributes))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'event handler' => [['onload' => 'alert(1)'], 'Unsafe asset attribute name'],
    'case-insensitive event handler' => [['onError' => true], 'Unsafe asset attribute name'],
    'invalid HTML name' => [['bad key' => 'value'], 'Unsafe asset attribute name'],
    'integer value' => [['data-count' => 1], 'must be a string or boolean'],
    'null value' => [['nonce' => null], 'must be a string or boolean'],
]);

it('rejects asset identifiers outside the shared wire format', function (): void {
    expect(fn () => Asset::script('Bad Asset', '/unsafe.js'))
        ->toThrow(InvalidArgumentException::class, 'Invalid asset ID');
});

it('renders hooks by priority and preserves registration order for ties', function (): void {
    $registry = new RenderHookRegistry;
    $registry
        ->register(RenderHook::make('second', 'body.end', fn (array $context): string => $context['suffix'], 10), 'b/plugin')
        ->register(RenderHook::make('first', 'body.end', fn (): string => 'A', 20), 'a/plugin')
        ->register(RenderHook::make('third', 'body.end', fn (): string => 'C', 10), 'c/plugin');

    expect($registry->render('body.end', ['suffix' => 'B']))->toBe('ABC');
});

it('registers then boots plugins deterministically and only once', function (): void {
    $events = new ArrayObject;
    $makePlugin = static fn (string $id): Plugin => new class($id, $events) implements Plugin
    {
        public function __construct(private string $pluginId, private ArrayObject $events) {}

        public function id(): string
        {
            return $this->pluginId;
        }

        public function register(PluginContext $context): void
        {
            $this->events[] = "register:{$this->pluginId}";
            $context->hostAs(stdClass::class);
        }

        public function boot(PluginContext $context): void
        {
            $this->events[] = "boot:{$this->pluginId}";
        }
    };

    $first = $makePlugin('acme/first');
    $second = $makePlugin('acme/second');
    $manager = new PluginManager('0.2.0', coreContext());
    $manager->load([
        [$first, ExtensionManifest::make('acme/first', requiresCore: '^0.2.0')],
        $second,
    ])->boot();

    expect($events->getArrayCopy())->toBe([
        'register:acme/first',
        'register:acme/second',
        'boot:acme/first',
        'boot:acme/second',
    ])->and($manager->plugins())->toBe([$first, $second])
        ->and($manager->manifest('acme/first')->id)->toBe('acme/first')
        ->and(fn () => $manager->register($first))->toThrow(LogicException::class);
});

it('rejects duplicate plugin IDs, mismatched manifests, and incompatible plugins', function (): void {
    $plugin = new class implements Plugin
    {
        public function id(): string
        {
            return 'acme/maps';
        }

        public function register(PluginContext $context): void {}

        public function boot(PluginContext $context): void {}
    };

    $manager = new PluginManager('0.2.0', coreContext());
    $manager->register($plugin);

    expect(fn () => $manager->register($plugin))->toThrow(InvalidArgumentException::class, 'already registered');

    $fresh = new PluginManager('0.2.0', coreContext());
    expect(fn () => $fresh->register($plugin, ExtensionManifest::make('other/maps')))
        ->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => $fresh->register($plugin, ExtensionManifest::make('acme/maps', requiresCore: '^1.0.0')))
        ->toThrow(InvalidArgumentException::class, 'requires Inlay core');
});

it('rolls back every shared registry when plugin registration fails and allows a clean retry', function (): void {
    $host = new stdClass;
    $extensions = (new ExtensionRegistry)
        ->define('services', stdClass::class)
        ->register('services', 'baseline', new stdClass, 'inlayphp/core');
    $assets = (new AssetRegistry)
        ->register(Asset::style('baseline-css', '/baseline.css'), 'inlayphp/core');
    $hooks = (new RenderHookRegistry)
        ->register(RenderHook::make('baseline', 'body.end', fn (): string => 'base'), 'inlayphp/core');
    $context = new PluginContext($host, $extensions, $assets, $hooks);
    $manager = new PluginManager(Inlay::VERSION, $context);

    $plugin = new class implements Plugin
    {
        private int $attempts = 0;

        public function id(): string
        {
            return 'acme/transactional';
        }

        public function register(PluginContext $context): void
        {
            $this->attempts++;
            $context->extensions()
                ->define('widgets', stdClass::class)
                ->register('widgets', 'weather', new stdClass, $this->id());
            $context->assets()->register(Asset::script('weather-js', '/weather.js'), $this->id());
            $context->renderHooks()->register(
                RenderHook::make('weather', 'body.end', fn (): string => 'weather'),
                $this->id(),
            );

            if ($this->attempts === 1) {
                throw new RuntimeException('Registration interrupted.');
            }
        }

        public function boot(PluginContext $context): void {}
    };

    expect(fn () => $manager->register($plugin))->toThrow(RuntimeException::class, 'Registration interrupted.')
        ->and($manager->has('acme/transactional'))->toBeFalse()
        ->and($extensions->all('services'))->toHaveCount(1)
        ->and($assets->all())->toHaveCount(1)
        ->and($hooks->render('body.end'))->toBe('base')
        ->and(fn () => $extensions->register('widgets', 'other', new stdClass, 'other/plugin'))
        ->toThrow(InvalidArgumentException::class, 'is not defined');

    $nextOrder = new ReflectionProperty($hooks, 'nextOrder');
    expect($nextOrder->getValue($hooks))->toBe(1);

    $manager->register($plugin)->boot();

    expect($manager->has('acme/transactional'))->toBeTrue()
        ->and($extensions->has('widgets', 'weather'))->toBeTrue()
        ->and($assets->has('weather-js'))->toBeTrue()
        ->and($hooks->render('body.end'))->toBe('baseweather')
        ->and($nextOrder->getValue($hooks))->toBe(2);
});

it('exposes one authoritative Inlay core version', function (): void {
    expect(Inlay::VERSION)->toBe('0.2.0')
        ->and((string) (new PluginManager(Inlay::VERSION, coreContext()))->coreVersion())->toBe(Inlay::VERSION);
});
