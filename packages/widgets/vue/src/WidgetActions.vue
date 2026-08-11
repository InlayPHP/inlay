<script setup lang="ts">
import type { ActionExecutionInput, ActionExecutor, ActionResource } from '@inlayphp/actions'
import { ActionButton } from '@inlayphp/actions-vue'
import { ActionForm } from '@inlayphp/forms-vue'

const noopExecutor: ActionExecutor = () => undefined

withDefaults(defineProps<{
  actions: ActionResource[]
  executor?: ActionExecutor
  input?: ActionExecutionInput
}>(), {
  executor: undefined,
  input: () => ({}),
})
</script>

<template>
  <div v-if="actions.length" class="flex flex-wrap justify-end gap-2" data-slot="widget-actions">
    <ActionButton
      v-for="action in actions"
      :key="action.instanceKey ?? action.name"
      :action="action"
      :executor="executor ?? noopExecutor"
      :form-renderer="ActionForm"
      :input="input"
    />
  </div>
</template>
