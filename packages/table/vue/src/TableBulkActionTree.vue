<script setup lang="ts">
import type { ActionExecutionContext, ActionGroupResource, ActionResource } from '@inlayphp/actions'
import type { TableRendererRegistries, TableRenderers, TableRow } from './types'
import TableAction from './TableAction.vue'
import TableActionGroup from './TableActionGroup.vue'

defineOptions({ name: 'TableBulkActionTree' })

defineProps<{
  definition: ActionResource | ActionGroupResource
  rows: TableRow[]
  recordKeys: Array<string | number>
  execute: (action: ActionResource, context: ActionExecutionContext) => unknown
  selectionReason: (action: ActionResource) => string | null
  complete: (action: ActionResource) => void
  registries?: TableRendererRegistries
  renderers?: TableRenderers
  groupPosition?: 'first' | 'middle' | 'last' | 'single'
}>()

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
</script>

<template>
  <TableActionGroup
    v-if="isGroup(definition)"
    :definition="definition"
    :group-position="groupPosition"
    :registries="registries"
    :renderers="renderers"
  >
    <TableBulkActionTree
      v-for="(child, index) in definition.actions"
      :key="child.name"
      :complete="complete"
      :definition="child"
      :group-position="definition.buttonGroup ? childPosition(index, definition.actions.length) : undefined"
      :execute="execute"
      :record-keys="recordKeys"
      :registries="registries"
      :renderers="renderers"
      :rows="rows"
      :selection-reason="selectionReason"
    />
  </TableActionGroup>
  <TableAction
    v-else
    :action="asAction(definition)"
    :disabled="selectionReason(asAction(definition)) !== null"
    :disabled-reason="selectionReason(asAction(definition))"
    :group-position="groupPosition"
    :executor="(context) => execute(asAction(definition), context)"
    :record-keys="recordKeys"
    :registries="registries"
    :renderers="renderers"
    :rows="rows"
    @success="complete(asAction(definition))"
  />
</template>
