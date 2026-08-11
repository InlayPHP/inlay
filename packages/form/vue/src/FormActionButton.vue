<script setup lang="ts">
import type { ActionExecutionInput, ActionExecutor, ActionResource } from '@inlayphp/actions'
import { ActionButton } from '@inlayphp/actions-vue'
import ActionForm from './ActionForm.vue'

withDefaults(defineProps<{
  action: ActionResource
  executor: ActionExecutor
  input?: ActionExecutionInput
  disabled?: boolean
}>(), {
  input: () => ({}),
  disabled: false,
})
</script>

<template>
  <ActionButton :action="action" :disabled="disabled" :executor="executor" :form-renderer="ActionForm" :input="input">
    <slot />
  </ActionButton>
</template>
