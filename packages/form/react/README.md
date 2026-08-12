# Inlay Forms for React

[![npm](https://img.shields.io/npm/v/@inlayphp/forms-react?style=flat-square)](https://www.npmjs.com/package/@inlayphp/forms-react)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**React renderer for Inlay PHP-first forms**

`@inlayphp/forms-react` renders the `inlay.forms.v1` contract produced by `inlayphp/forms`. It manages defaults, nested state, conditional visibility, errors, live events, optional Precognition validation and Inertia submission.

## Install

```bash
pnpm add @inlayphp/forms-react @inlayphp/core @inertiajs/react react react-dom
composer require inlayphp/forms
```

Peer support targets Inertia React 3 and React 19.

## Basic use

```tsx
import { Form } from '@inlayphp/forms-react'
import type { FormErrors, FormResource } from '@inlayphp/forms-react'

type Props = { userForm: FormResource; errors: FormErrors }

export default function CreateUser({ userForm, errors }: Props) {
  return <Form resource={userForm} errors={errors} />
}
```

Without `onSubmit`, the component calls `router.visit(resource.action)` using the serialized method and current data. Submission does nothing when the action is null. Pass `processing` to disable the button and show “Saving…”.

## Controlled application behavior

```tsx
<Form
  resource={userForm}
  errors={errors}
  processing={saving}
  onChange={(data) => setDraft(data)}
  onSubmit={(data) => saveThroughApi(data)}
  onLiveChange={({ path, value, data, config }) => {
    updatePreview(path, value, data)
  }}
  onValidationError={(error) => report(error)}
/>
```

Providing `onSubmit` gives the application complete submission ownership. Initial data is computed by merging field defaults with `resource.data`; changing the resource resets local values and live-validation state.

## Live validation

When PHP serializes a validation class and `precognitive()` configuration, the default `validateWithPrecognition` transport sends field-aware requests to the form action. Requests are cancelled when newer validation supersedes them, errors render inline, and the form exposes an accessible validating state.

Override the transport when necessary:

```tsx
import type { FormValidator } from '@inlayphp/forms-react'

const validator: FormValidator = async ({ path, data, signal }) => {
  const response = await validateDraft({ path, data, signal })
  return response.errors
}

<Form resource={userForm} validator={validator} />
```

The validator returns `Record<string, string>`. PHP still owns the final authoritative validation.

## Custom renderers

Wire component `type` values are renderer keys. Local renderers take precedence over Core registries and built-ins:

```tsx
import type { SchemaComponentRenderer } from '@inlayphp/forms-react'

const CurrencyField: SchemaComponentRenderer = ({ component, path, value, update }) => (
  <label>
    {component.label}
    <input
      name={path}
      type="number"
      value={String(value ?? '')}
      onChange={(event) => update(path, Number(event.target.value))}
    />
  </label>
)

<Form
  resource={userForm}
  renderers={{ 'vendor-currency': CurrencyField }}
  registries={appRendererRegistries}
/>
```

Community payloads should set `rendererCategory: "schema"`, `"field"`, or `"layout"`. Registry sets expose separate `schema.get(type)`, `field.get(type)`, and `layout.get(type)` lookups. Schema content does not receive a form state path. Custom renderers receive the same values, errors, update/live functions and nested renderer context.

`SchemaRenderer` is exported for advanced composition. `evaluateCondition`, `validateWithPrecognition`, all wire-resource types and renderer context types are public exports.

## Theme and styling

```tsx
<Form
  resource={userForm}
  className="max-w-3xl"
  theme={{
    accent: '#7c3aed',
    radius: '0.75rem',
    surface: '#ffffff',
    surfaceMuted: '#f4f4f5',
    foreground: '#18181b',
    muted: '#71717a',
    border: 'rgb(24 24 27 / 0.12)',
    danger: '#dc2626',
  }}
/>
```

Theme values become inherited `--inlay-*` variables and otherwise fall back to panel/default variables. Markup includes stable `data-contract`, `data-slot`, and `data-field` attributes. Safe PHP `extraAttributes` are applied to wrappers; event handlers, raw HTML, `style`, refs and React internals are filtered.

For Tailwind CSS 4, make package source discoverable when it is outside normal application scanning:

```css
@import 'tailwindcss';
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
```

The path is relative to the application stylesheet. The full Inlay installer
adds this rule automatically.

## Payload expectations

`FormResource` contains `contract`, `name`, `action`, `method`, `columns`, `submitLabel`, validation metadata, data and schema. Built-ins render every current PHP field and the shared Section/Grid/Group/Tabs/Wizard/Fieldset/Callout layouts.

Builder and Repeater rows receive opaque React-only keys so moving a row keeps its local
editor, select, upload, and collapse state. These keys are renderer metadata,
never form data: `onSubmit` receives the unchanged `{ type, data }` item shape.
Do not persist or validate a React key in application code.

## Test, typecheck and build

```bash
pnpm test -- --run
pnpm typecheck
pnpm build
```

The build emits side-effect-free ESM and TypeScript declarations.

## Related packages

- `inlayphp/forms`: PHP builder and payload.
- `@inlayphp/forms-vue`: Vue adapter for the same contract.
- `@inlayphp/core`: renderer registries and URL utilities.
- `inlayphp/validation`: centralized Laravel validation.
