<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { CSSProperties } from 'vue'
import type { WidgetResource } from './types'

/**
 * The cell one widget lives in, and the two behaviours PHP declares for it.
 *
 * A lazy widget stays a placeholder until it is scrolled into view, and asks
 * once for fresh data when it arrives. A polling widget asks again on its own
 * interval, but only while it is shown and the tab is actually being looked at,
 * so a background tab does not keep the server busy. Both go through the host's
 * `refresh` event, so the transport stays the application's decision.
 */
const props = withDefaults(defineProps<{ widget: WidgetResource; cellStyle?: CSSProperties; className?: string }>(), {
  cellStyle: () => ({}),
  className: '',
})
const emit = defineEmits<{ refresh: [name: string] }>()

const observable = typeof IntersectionObserver !== 'undefined'
const shown = ref(!props.widget.lazy || !observable)
const cell = ref<HTMLElement | null>(null)
const requested = ref(!props.widget.lazy)
let observer: IntersectionObserver | null = null
let timer: ReturnType<typeof setInterval> | null = null

const polling = computed(() => props.widget.pollingInterval)

function stopPolling() {
  if (timer !== null) {
    clearInterval(timer)
    timer = null
  }
}

function startPolling() {
  stopPolling()
  const seconds = polling.value
  if (!shown.value || !seconds) return
  timer = setInterval(() => {
    if (!document.hidden) emit('refresh', props.widget.name)
  }, seconds * 1000)
}

onMounted(() => {
  if (!shown.value && observable && cell.value) {
    observer = new IntersectionObserver((entries) => {
      if (entries.some(entry => entry.isIntersecting)) {
        observer?.disconnect()
        observer = null
        shown.value = true
      }
    })
    observer.observe(cell.value)
  }
  startPolling()
})

watch(shown, (visible) => {
  if (!visible) return
  if (!requested.value) {
    requested.value = true
    emit('refresh', props.widget.name)
  }
  startPolling()
})

watch(polling, startPolling)

onBeforeUnmount(() => {
  observer?.disconnect()
  stopPolling()
})
</script>

<template>
  <div
    ref="cell"
    :class="['min-w-0', className]"
    :data-lazy="widget.lazy ? 'true' : undefined"
    :data-polling="widget.pollingInterval ?? undefined"
    data-slot="widget"
    :data-widget="widget.name"
    :style="cellStyle"
  >
    <slot v-if="shown" />
    <div
      v-else
      aria-busy="true"
      class="h-32 rounded-(--inlay-widget-radius) bg-(--inlay-widget-muted-surface)"
      data-slot="widget-placeholder"
    />
  </div>
</template>
