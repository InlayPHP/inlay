# Architecture

## Decisions

1. **Clean core plus optional plugins.** `inlayphp/inlay` installs the panel, Resource, and reusable UI foundation. Database permissions, media management, Spatie adapters, and imports remain independently versioned official packages. Consumers install only the integrations they choose.
2. **PHP owns intent; the frontend owns interaction.** Fluent builders describe fields, columns, filters, and actions. They serialize to a versioned JSON contract that React and Vue can render.
3. **No arbitrary PHP closures cross the Inertia boundary.** Dynamic server behavior must be resolved before serialization or represented by explicit route/action metadata.
4. **Renderer-neutral contract.** The same payload must work in React and Vue. Adapter-specific slots remain frontend concerns.
5. **Progressive enhancement.** The first release should make common CRUD screens excellent before adding long-tail relationship editors or complex reactive schemas. Server-authored and opt-in owner-scoped personal table views share the same data-only contract.

## Package layout

```text
packages/
  support/       # Shared serializable contracts, conditions, and styling primitives
  validation/    # Central Laravel rules shared by forms, requests, imports, APIs, and actions
  schemas/       # Layout/content schema primitives shared by forms and infolists
  actions/       # Trigger, confirmation, modal, endpoint, and bulk-action contracts
  import/        # Import definitions, validation, execution, and React/Vue wizards
  panel/         # Multi-panel providers, navigation contracts, and React/Vue shells
  form/          # Composer package: inlayphp/forms
    composer.json
    src/
    react/       # React 19 renderer and tests
    vue/         # Vue 3 renderer and tests
  table/         # Composer package: inlayphp/tables
    composer.json
    src/
    react/       # React 19 renderer and tests
    vue/         # Vue 3 renderer and tests
  infolist/       # Composer package: inlayphp/infolists
    composer.json
    src/
    react/       # Read-only React entry renderers
    vue/         # Read-only Vue entry renderers
```

Published frontend packages are installed from npm under `node_modules/@inlayphp`;
the monorepo uses pnpm workspaces only while developing them together.

## Package dependencies

```text
inlayphp/inlay (clean distribution)
  ├── panels
  │     ├── authorization
  │     ├── core
  │     ├── support
  │     ├── theme
  │     └── widgets ──> tables ──> actions ──> support
  └── resources
        ├── authorization
        ├── forms ──> schemas, support, validation
        ├── infolists ──> schemas, support
        ├── panels
        ├── tables
        ├── support
        └── validation

optional official packages
  ├── imports ──> validation
  ├── authorization-spatie ──> authorization
  ├── permission-manager ──> authorization-spatie + panel UI foundation
  ├── media (storage-neutral catalog)
  ├── media-manager ──> media + panels + authorization + actions
  └── media-spatie ──> media
```

- `support` owns renderer-neutral JSON value objects and contract utilities. It must not depend on a UI feature package.
- `validation` owns server-side validation classes and contexts. It accepts native Laravel rules and remains independent from UI schemas and HTTP Form Requests.
- `schemas` owns layout and static-content components, but not editable form fields or read-only infolist entries.
- `actions` owns action metadata and execution UI. Modal form content is integrated through an optional adapter so `table` does not need to install `form`.
- `form`, `infolist`, and `table` own their domain components and compose the shared packages.
- No clean-core package may require an optional plugin. Plugin-to-core and plugin-to-adapter dependencies are allowed.
- `authorization` stays in core because Laravel Gate and Policy decisions are universal; permission storage and its management UI remain optional.
- `media` does not know about Panels or Spatie. `media-manager` supplies panel UI, while `media-spatie` is an independent bridge.
- The playground explicitly installs every official plugin because it is an integration demo, not the dependency definition of `inlayphp/inlay`.
- Pre-release namespace changes do not require a compatibility layer before the first public release.

Notifications are deliberately deferred until actions support asynchronous completion. They should become a separate package instead of table- or form-specific behavior.

## Public contract

Every top-level resource contains a contract version and a type:

```json
{
  "contract": "inlay.forms.v1",
  "type": "form",
  "name": "create-user",
  "schema": []
}
```

Component payloads use stable string types such as `text`, `select`, `text-column`, and `select-filter`. New optional keys are backward compatible; changing the meaning or shape of existing keys requires a new contract version.

## Delivered v1

- Versioned PHP-to-frontend contracts locked by Pest tests.
- Complete v1 form and schema component catalog.
- Complete v1 table column and filter catalog.
- Allow-listed Eloquent search, sorting, filtering, and pagination.
- React 19 and Vue 3 renderers with matching interaction tests.
- Buildable Tailwind CSS 4 examples for both adapters.
- Central validation classes with reusable operation/source contexts, preparation, messages, attributes, and after hooks.
- Fault-isolated import execution and matching five-step React/Vue import wizards.
- Read-only infolist entry catalog with nested layouts and matching React/Vue renderers.
- Multi-panel registry/provider foundation with accessible React/Vue application shells.

## Next releases

- Resource-driven CRUD pages built on the delivered panels, forms, tables, actions, schemas, validation, imports, and infolists contracts.
- Serializable reactive conditions and named server endpoints; PHP closures never enter the JSON contract.
- Semantic CSS variables, stable `data-slot` names, typed class overrides, and custom renderer registries.
- Async/relationship option loaders and direct-to-storage uploads.
- Rich editor engine integrations and custom renderer registries.
- Personal saved-view persistence, exports, summaries, grouping, and advanced query-builder clauses share the delivered table contract. Personal views are opt-in and use the replaceable session/database store boundary; further work is release hardening and richer query-builder coverage.
- Form Request, form, and import adapters for centralized validation classes, followed by Artisan generators and Laravel auto-discovery service providers.
