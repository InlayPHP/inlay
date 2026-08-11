# Inlay Core

[![Packagist](https://img.shields.io/packagist/v/inlayphp/core?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/core)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/core/php?style=flat-square)](https://packagist.org/packages/inlayphp/core)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Plugin, extension, asset, and render hook contracts for Inlay**

The stable extension kernel shared by Inlay hosts and community plugins. It provides:

- a host-neutral plugin lifecycle through `PluginContext`;
- versioned extension manifests and semantic compatibility checks;
- collision-safe, typed extension and asset registries;
- deterministic, prioritized render hooks.

The current PHP contract version is available from `Inlay\Core\Inlay::VERSION`.

## Install

```bash
composer require inlayphp/core
```

Applications normally receive Core through `inlayphp/inlay` or `inlayphp/panels`. Install it directly when building a host or a community plugin that needs lifecycle, extension, asset, or render-hook contracts without the panel runtime.

Hosts create one context and manager, register all plugins, then boot them:

```php
$context = new PluginContext($panel, new ExtensionRegistry(), new AssetRegistry(), new RenderHookRegistry());
$manager = new PluginManager('0.2.0', $context);
$manager->load([$plugin]);
```

Use an explicit `ExtensionManifest` for publishable plugins so incompatible core versions fail before registration has side effects.

Assets use the same transport-neutral contract as `@inlayphp/core`:

```php
$asset = Asset::script(
    id: 'acme:maps',
    source: '/vendor/acme/maps.js',
    lazy: true,
    attributes: ['defer' => true, 'nonce' => 'request-nonce'],
);

$context->assets()->register($asset, 'acme/maps');
$scripts = $context->assets()->all(Asset::SCRIPT);
```

Serialized assets contain `id`, `source`, `kind`, `lazy`, and `attributes`. Attribute values are strings or booleans; unsafe event-handler attributes are rejected.

## Extension and render-hook ownership

Every registration records an owner. Duplicate names fail instead of silently replacing another package, and a registry checkpoint can roll back partial plugin registration when a later contribution fails. Render hooks are sorted deterministically by priority and registration order, which keeps output stable across environments.

Publishable plugins should expose a stable ID, use an `ExtensionManifest` with the supported Core range, perform declarations in `register()`, and defer container-dependent work to `boot()`. A host should create one `PluginContext` per isolation boundary, such as one Panel.

## Frontend companion

`@inlayphp/core` mirrors contract compatibility checks, renderer registries, asset manifests, and safe URL helpers without depending on React, Vue, the DOM, or Inertia. Community packages can therefore ship one PHP plugin and separate renderer adapters while keeping the wire contract version explicit.
