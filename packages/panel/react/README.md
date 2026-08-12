# Inlay Panels for React

[![npm](https://img.shields.io/npm/v/@inlayphp/panels-react?style=flat-square)](https://www.npmjs.com/package/@inlayphp/panels-react)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**React renderer for Inlay administration panels**

Render a responsive application shell from the `inlay.panels.v1` PHP resource.

## Install

```bash
pnpm add @inlayphp/panels-react @inlayphp/forms-react @inlayphp/ui-react @inlayphp/core @inertiajs/react react react-dom
```

The server payload is produced by `inlayphp/panels`. Add the package source to Tailwind v4 when dependencies are not scanned automatically:

```css
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
```

## Basic usage

```tsx
import { Panel } from '@inlayphp/panels-react'
import type { PanelResource } from '@inlayphp/panels-react'
import type { PropsWithChildren } from 'react'

export function AppLayout({ panel, children }: PropsWithChildren<{ panel: PanelResource }>) {
  return (
    <Panel resource={panel}>
      {children}
    </Panel>
  )
}
```

For Inertia or another SPA router, provide a link component or navigation callback:

```tsx
<Panel
  resource={panel}
  linkComponent={({ href, children, ...props }) => <Link href={href} {...props}>{children}</Link>}
>
  {children}
</Panel>
```

Navigation groups and items are sorted, filtered, and activated from the PHP contract. Client-only conditions can receive `conditionValues`; conditional visibility fails closed when the context or path is unavailable. Use `theme`, `classNames`, `icons`, `renderers`, and named slots for customization.

```tsx
<Panel
  resource={panel}
  conditionValues={{
    permissions: page.props.auth.permissions,
    route: page.props.routeName,
  }}
  onNavigate={(href) => router.visit(href)}
>
  {children}
</Panel>
```

Static PHP visibility remains authoritative. Browser conditions only control presentation; Laravel policies and middleware must protect the underlying endpoints.

### Theme contract

`theme` accepts either a plain semantic token map or the serialized
`inlay.themes.v1` contract returned by `Inlay\Theme\Theme` / `Inlay\Design\Design`:

```tsx
<Panel resource={panel} theme={page.props.inlayTheme}>
  {children}
</Panel>
```

Contract `tokens` drive light mode and `darkTokens` are merged for dark mode.
Unknown semantic keys are emitted in a scoped stylesheet, while child Forms,
Tables, Infolists, Widgets, Imports, Media Manager, and Permission Manager
components inherit the same `--inlay-*` values. Use `classNames`, slots, and
renderers for structure/content; use semantic tokens for application-wide
visual changes.

## Account settings

When the PHP panel enables `->accountSettings()`, create the Inertia page named `inlay/account-settings` and wrap the provided renderer in your panel layout:

```tsx
import { AccountSettingsPage } from '@inlayphp/panels-react'
import type { AccountSettingsPageProps } from '@inlayphp/panels-react'

export default function AccountSettings(props: AccountSettingsPageProps) {
  return <AdminLayout><AccountSettingsPage {...props} /></AdminLayout>
}
```

Both sections are ordinary `inlay.forms.v1` resources rendered by `@inlayphp/forms-react`, so app themes and form renderer overrides apply without a second component system.

## Panel discovery

For a user who can enter more than one registered panel, share the minimal PHP
directory contract from `PanelRegistry::directoryFor($user)` and render it with
the built-in switcher:

```tsx
import { PanelSwitcher } from '@inlayphp/panels-react'

<PanelSwitcher
  currentPanelId={panel.id}
  panels={page.props.inlayPanels}
  onNavigate={(href) => router.visit(href)}
/>
```

The component removes the active panel and rejects unsafe URLs before rendering.
Enter/Space and ArrowUp/ArrowDown open and cycle the menu; Escape, outside
clicks, and navigation close it and restore focus to the trigger. Authorization
stays server-owned.

## Renderer and layout extension

`renderComponent` can resolve from an app-owned Core layout registry. A component supplied directly to the panel remains the higher-priority override, and an unknown key safely renders the original children.

```tsx
<Panel
  resource={panel}
  registries={appRenderers}
  renderers={{ components: { 'admin-layout': AdminLayout } }}
>
  {children}
</Panel>
```

Direct component overrides take precedence over the shared Core registry. Unknown renderer keys safely fall back to the standard shell, making gradual application-level customization possible.

The shell exposes stable `data-slot` attributes plus typed class hooks for the root, header, brand, sidebar, top navigation, groups, items, active items, badges, main content, breadcrumbs, user menu, and mobile overlay. Supply an icon map keyed by the PHP icon names, or replace brand, navigation-item, and user-menu renderers entirely.

## Accessibility and testing

The standard renderer includes labelled navigation landmarks, `aria-current`, expandable state, a mobile navigation dialog with Escape handling, and keyboard-aware user menus. Test custom renderers with Testing Library after replacing structural slots so these behaviors remain intact.

From the monorepo:

```bash
pnpm --filter @inlayphp/panels-react test -- --run
pnpm --filter @inlayphp/panels-react typecheck
pnpm --filter @inlayphp/panels-react build
```
