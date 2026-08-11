<script setup lang="ts">
import TableAction from './TableAction.vue'
import type { Action, TableClassNames, TableRow } from './types'
import type { ActionExecutionContext } from '@inlayphp/actions'

/**
 * The row action cell, in one place rather than three.
 *
 * PHP decides where this sits among the row's cells, and the three positions
 * are different points in the DOM — so the markup lives here and Table.vue
 * mounts it at whichever point PHP named, instead of carrying three copies
 * that would drift apart.
 */
const props = withDefaults(defineProps<{
  actions: Action[]
  /** The cell and its action row are host-stylable in React, so they are here too. */
  classNames?: TableClassNames
  row: TableRow
  recordKey: string | number
  cardLayout: boolean
  visible: (condition: Action['visibleWhen'], row: TableRow) => boolean
  execute: (action: Action, rows: TableRow[], context?: ActionExecutionContext) => unknown
  complete: (action: Action) => void
  registries?: unknown
  renderers?: unknown
}>(), { classNames: () => ({}) })
</script>

<template>
  <td :class="`${cardLayout ? 'block px-2 py-2' : 'w-max min-w-32 whitespace-nowrap border-l border-(--inlay-border) bg-(--inlay-surface) px-3 py-3 group-hover:bg-(--inlay-hover) group-focus-within:bg-(--inlay-hover) lg:sticky lg:right-0 lg:z-10 lg:shadow-[-8px_0_12px_-12px_rgb(0_0_0_/_0.35)]'} text-right ${props.classNames.cell ?? ''}`">
    <div :class="`flex justify-end gap-2 whitespace-nowrap ${props.classNames.rowActions ?? ''}`" data-slot="row-actions">
      <template v-for="action in actions" :key="action.instanceKey ?? action.name">
        <TableAction
          v-if="visible(action.visibleWhen, row)"
          :action="action"
          :executor="(context: ActionExecutionContext) => execute(action, [row], context)"
          :record-keys="[recordKey]"
          :registries="registries as never"
          :renderers="renderers as never"
          :rows="[row]"
          @success="complete(action)"
        />
      </template>
    </div>
  </td>
</template>
