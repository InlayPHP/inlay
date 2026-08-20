<script setup lang="ts">
import type { ActionExecutionContext, ActionGroupResource, ActionResource } from '@inlayphp/actions'
import type { TableRendererRegistries, TableRenderers, TableRow } from './types'
import TableAction from './TableAction.vue'
import TableActionGroup from './TableActionGroup.vue'

defineOptions({ name: 'TableRowActionTree' })

const props = withDefaults(defineProps<{
  definition: ActionResource | ActionGroupResource
  rows: TableRow[]
  recordKeys: Array<string | number>
  execute: (action: ActionResource, rows: TableRow[], context?: ActionExecutionContext) => unknown
  complete: (action: ActionResource) => void
  visible: (condition: ActionResource['visibleWhen'], row: TableRow) => boolean
  registries?: TableRendererRegistries
  renderers?: TableRenderers
  groupPosition?: 'first' | 'middle' | 'last' | 'single'
}>(), { rows: () => [], recordKeys: () => [] })

function isGroup(definition: ActionResource | ActionGroupResource): definition is ActionGroupResource {
  return definition.type === 'action-group' && 'actions' in definition
}

function asAction(definition: ActionResource | ActionGroupResource): ActionResource {
  return definition as ActionResource
}

function childPosition(index: number, count: number): 'first' | 'middle' | 'last' | 'single' {
  if (count === 1) return 'single'
  if (index === 0) return 'first'
  if (index === count - 1) return 'last'
  return 'middle'
}

function hasVisibleAction(definition: ActionResource | ActionGroupResource): boolean {
  if (isGroup(definition)) return definition.actions.some(hasVisibleAction)
  return props.visible(definition.visibleWhen, props.rows[0] ?? {})
}
</script>

<template>
  <TableActionGroup
    v-if="isGroup(definition) && hasVisibleAction(definition)"
    :context="'row'"
    :definition="definition"
    :group-position="groupPosition"
    :registries="registries"
    :renderers="renderers"
  >
    <TableRowActionTree
      v-for="(child, index) in definition.actions"
      :key="child.instanceKey ?? child.name"
      :complete="complete"
      :definition="child"
      :group-position="definition.buttonGroup ? childPosition(index, definition.actions.length) : undefined"
      :execute="execute"
      :record-keys="recordKeys"
      :registries="registries"
      :renderers="renderers"
      :rows="rows"
      :visible="visible"
    />
  </TableActionGroup>
  <TableAction
    v-else-if="!isGroup(definition) && visible(asAction(definition).visibleWhen, rows[0] ?? {})"
    :action="asAction(definition)"
    :executor="(context) => execute(asAction(definition), rows, context)"
    :group-position="groupPosition"
    :record-keys="recordKeys"
    :registries="registries"
    :renderers="renderers"
    :rows="rows"
    @success="complete(asAction(definition))"
  />
</template>
