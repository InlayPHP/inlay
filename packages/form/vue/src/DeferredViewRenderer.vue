<script setup lang="ts">
import { loadDeferredView } from '@inlayphp/core'
import { computed, onBeforeUnmount, onMounted, ref, useAttrs, watch } from 'vue'
import type { Component } from 'vue'
import type { FormComponent } from './types'

defineOptions({ inheritAttrs: false })
const props = defineProps<{ renderer?: Component; component: FormComponent }>()
const attrs = useAttrs()
const attempt = ref(0)
const data = ref<Record<string, unknown> | null>(null)
const failed = ref(false)
const ready = ref(props.component.lazy !== true)
const anchor = ref<HTMLElement | null>(null)
let controller: AbortController | null = null
let observer: IntersectionObserver | null = null

onMounted(() => {
  if (!props.component.lazy || typeof IntersectionObserver === 'undefined') {
    ready.value = true
    return
  }

  observer = new IntersectionObserver(([entry]) => {
    if (entry?.isIntersecting) {
      ready.value = true
      observer?.disconnect()
    }
  }, { rootMargin: '200px 0px' })
  if (anchor.value) observer.observe(anchor.value)
})

watch(
  [() => props.component.deferredEndpoint, () => props.component.view, attempt, ready],
  async ([endpoint, view, , canLoad]) => {
    controller?.abort()
    if (!canLoad) return
    controller = new AbortController()
    failed.value = false
    data.value = null
    if (!endpoint || !view) {
      failed.value = true
      return
    }

    try {
      const payload = await loadDeferredView({
        endpoint,
        view,
        name: props.component.name,
        signal: controller.signal,
      })
      if (!controller.signal.aborted) data.value = payload.data
    } catch {
      if (!controller.signal.aborted) failed.value = true
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  controller?.abort()
  observer?.disconnect()
})

const resolvedProps = computed(() => ({
  ...attrs,
  component: { ...props.component, data: data.value ?? {} },
}))
</script>

<template>
  <div v-if="failed" class="rounded-(--inlay-radius) border border-(--inlay-danger)/25 bg-(--inlay-danger)/5 p-3 text-sm text-(--inlay-danger)" data-slot="deferred-view-error" role="alert">
    <p>{{ component.errorMessage ?? 'This content could not be loaded.' }}</p>
    <button v-if="component.retryable !== false" class="mt-2 rounded-(--inlay-radius) border border-current/25 px-2.5 py-1 font-semibold hover:bg-current/5 focus-visible:outline-2 focus-visible:outline-offset-2" type="button" @click="attempt++">Retry</button>
  </div>
  <div v-else-if="data === null" ref="anchor" aria-live="polite" class="animate-pulse rounded-(--inlay-radius) bg-(--inlay-surface-muted) p-3 text-sm text-(--inlay-muted)" :data-lazy="component.lazy ? 'true' : undefined" data-slot="deferred-view-loading" role="status">{{ component.loadingMessage ?? 'Loading…' }}</div>
  <component :is="renderer" v-else v-bind="resolvedProps" />
</template>
