# Inlay UI for React

[![npm](https://img.shields.io/npm/v/@inlayphp/ui-react?style=flat-square)](https://www.npmjs.com/package/@inlayphp/ui-react)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Accessible React primitives and shared control styling for Inlay packages**

Shared accessible primitives used by the official Inlay React renderers. Applications normally receive this package transitively through Forms, Tables, Panels, or plugins.

```tsx
import { Select, buttonPrimaryClass, controlClass } from '@inlayphp/ui-react'

<Select
  ariaLabel="Status"
  options={[{ value: 'active', label: 'Active' }]}
  value={status}
  onValueChange={setStatus}
/>

<button className={buttonPrimaryClass} type="button">Save</button>
```

`Select` provides a keyboard-aware combobox/listbox, click-outside dismissal, active and selected states, and styling through the standard Inlay CSS variables. `controlClass` keeps text inputs and custom controls visually aligned across official packages.
Use `buttonSecondaryClass`, `buttonSmallClass`, and `buttonLargeClass` for
matching neutral, compact, and prominent action variants. Their heights come
from the same semantic theme tokens as Forms, Tables, Panels, and plugins.

`Dialog` provides labelled dialog semantics, a focus trap, focus return, Escape
and backdrop dismissal, and portal rendering. It is intentionally content
agnostic, so Forms, Tables, Actions, media details, and community packages can
share the same interaction boundary:

```tsx
<Dialog open={open} onOpenChange={setOpen} title="Details">
  <Form resource={form} />
</Dialog>
```
