# Inlay community schema view template

This directory is a tested starting point for a community component that ships one Composer API plus React and Vue adapters.

## Rename before publishing

Replace:

- `acme/inlay-order-summary`
- `@acme/inlay-order-summary`
- `Acme\InlayOrderSummary`
- `acme/order-summary`

Keep the PHP `VIEW` constant and both frontend `orderSummaryView` exports identical. That stable name connects the Inertia contract to either renderer.

Remove `"private": true` from `package.json` only when the npm package metadata is ready.

## PHP usage

```php
use Acme\InlayOrderSummary\OrderSummary;

OrderSummary::make(fn (Order $record): array => [
    'number' => $record->number,
    'total' => $record->formattedTotal(),
])->schema([
    Text::make('Payment captured')->color('success'),
]);
```

All data remains subject to `inlayphp/schemas` wire-safety validation. Add `->defer()` for expensive data on an Inlay `FormPage` or Resource display route, or `->lazy()` to wait until the island approaches the viewport.

## React adapter

```tsx
import { createRendererRegistries } from '@inlayphp/core'
import type { FormRendererRegistryTypes } from '@inlayphp/forms-react'
import { registerOrderSummary } from '@acme/inlay-order-summary/react'

export const registries = createRendererRegistries<FormRendererRegistryTypes>()
registerOrderSummary(registries)
```

## Vue adapter

```ts
import { createRendererRegistries } from '@inlayphp/core'
import type { FormRendererRegistryTypes } from '@inlayphp/forms-vue'
import { registerOrderSummary } from '@acme/inlay-order-summary/vue'

export const registries = createRendererRegistries<FormRendererRegistryTypes>()
registerOrderSummary(registries)
```

Pass the shared registry to the Inlay Form/Infolist root or install it through the host application's plugin bootstrap. Do not register renderers inside page render functions.

## Compatibility checks

```bash
composer test
pnpm test -- --run
pnpm typecheck
pnpm build
```

The included tests prove:

- PHP emits a JSON-round-trippable `view` contract.
- React and Vue register the same view name.
- Registry ownership is explicit and collisions fail.
- Nested Inlay schema remains available through `renderSchema()`.
