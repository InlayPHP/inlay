# Inlay UI for Vue

[![npm](https://img.shields.io/npm/v/@inlayphp/ui-vue?style=flat-square)](https://www.npmjs.com/package/@inlayphp/ui-vue)

Accessible Vue primitives and shared design recipes for Inlay packages. The
package is the Vue counterpart to `@inlayphp/ui-react`; both renderers expose
the same Select and Dialog interaction contracts and read the same `@inlayphp/ui`
theme tokens.

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { Dialog } from '@inlayphp/ui-vue'

const open = ref(false)
</script>

<button type="button" @click="open = true">Open details</button>
<Dialog v-model:open="open" title="Details" description="Review the record before saving.">
  <p>Any Form, Table, or community content can be rendered here.</p>
</Dialog>
```

`Dialog` provides labelled dialog semantics, focus trapping, focus return,
Escape dismissal, backdrop dismissal, and Teleport-to-body rendering. Pass
`close-on-escape="false"` or `close-on-backdrop="false"` for destructive or
multi-step workflows. `class-name` and `backdrop-class-name` let an application
extend the default Inlay surface without replacing its accessibility behavior.

`@inlayphp/ui` remains the renderer-neutral source of class recipes and icon
resolution. Applications normally receive it transitively through Forms,
Tables, Panels, or plugins.

Import `buttonPrimaryClass`, `buttonSecondaryClass`, `buttonSmallClass`, or
`buttonLargeClass` for actions that should align with the rest of the Inlay
surface. Their geometry is controlled by the shared theme tokens, so changing
one theme updates every official control.

`Select` is a keyboard-aware combobox/listbox with disabled options,
searchable and multiple modes, hidden form inputs, and async loading/empty
states. Its `v-model` value and `searchChange` event match the React
`onValueChange` and `onSearchChange` boundary.
