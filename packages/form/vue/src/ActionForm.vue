<script setup lang="ts">
import type { VueActionRuntime } from '@inlayphp/actions-vue'
import { computed } from 'vue'
import Form from './Form.vue'
import type { FormErrors, FormResource } from './types'

const props = defineProps<{ controller: VueActionRuntime }>()
const form = computed(() => props.controller.state.value.form as FormResource | null)
const errors = computed<FormErrors>(() => Object.fromEntries(
  Object.entries(props.controller.state.value.validationErrors)
    .filter(([, messages]) => messages.length > 0)
    .map(([path, messages]) => [path, messages[0]!]),
))
function change(data: Record<string, unknown>) {
  props.controller.setData(data)
}
</script>

<template>
  <Form
    v-if="form"
    :errors="errors"
    manual
    :processing="controller.state.value.phase === 'executing'"
    :resource="form"
    :show-submit="false"
    @change="change"
    @submit="controller.confirm"
  />
</template>
