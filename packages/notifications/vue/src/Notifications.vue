<script setup lang="ts">
import { isSafeUrl } from '@inlayphp/core'
import { buttonSmallClass, cardClass, iconButtonClass } from '@inlayphp/ui'
import { normalizeNotifications } from '@inlayphp/notifications'
import type { NotificationPosition, NotificationRecord, NotificationStatus } from '@inlayphp/notifications'
import { computed, onBeforeUnmount, ref, watch } from 'vue'

const props = withDefaults(defineProps<{
  notifications?: unknown
  position?: NotificationPosition
  className?: string
  itemClassName?: string
  ariaLabel?: string
}>(), {
  notifications: () => [],
  position: 'top-right',
  className: '',
  itemClassName: '',
  ariaLabel: 'Notifications',
})

const emit = defineEmits<{ dismiss: [id: string] }>()
const dismissed = ref(new Set<string>())
const timers = new Map<string, number>()

const records = computed(() => normalizeNotifications(props.notifications))
const visible = computed(() => records.value.filter(record => !dismissed.value.has(record.id)))

const tone: Record<NotificationStatus, string> = {
  success: 'border-(--inlay-success) bg-(--inlay-success-surface)',
  info: 'border-(--inlay-info) bg-(--inlay-info-surface)',
  warning: 'border-(--inlay-warning) bg-(--inlay-warning-surface)',
  danger: 'border-(--inlay-danger) bg-(--inlay-danger-surface)',
}
const positionClass: Record<NotificationPosition, string> = {
  'top-left': 'top-4 left-4 items-start',
  'top-center': 'top-4 left-1/2 -translate-x-1/2 items-center',
  'top-right': 'top-4 right-4 items-end',
  'bottom-left': 'bottom-4 left-4 items-start',
  'bottom-center': 'bottom-4 left-1/2 -translate-x-1/2 items-center',
  'bottom-right': 'bottom-4 right-4 items-end',
}

function dismiss(id: string) {
  dismissed.value = new Set(dismissed.value).add(id)
  const timer = timers.get(id)
  if (timer !== undefined) window.clearTimeout(timer)
  timers.delete(id)
  emit('dismiss', id)
}

function schedule(record: NotificationRecord) {
  if (record.persistent || record.duration === null || record.duration === undefined || record.duration <= 0 || timers.has(record.id)) return
  timers.set(record.id, window.setTimeout(() => dismiss(record.id), record.duration))
}

watch(records, next => {
  const ids = new Set(next.map(record => record.id))
  dismissed.value = new Set([...dismissed.value].filter(id => ids.has(id)))
  for (const record of next) schedule(record)
  for (const [id, timer] of timers) {
    if (!ids.has(id)) { window.clearTimeout(timer); timers.delete(id) }
  }
}, { immediate: true })

onBeforeUnmount(() => { for (const timer of timers.values()) window.clearTimeout(timer) })
</script>

<template>
  <section v-if="visible.length" :aria-label="props.ariaLabel" aria-live="polite" :class="['pointer-events-none fixed z-[100] flex w-[min(calc(100vw-2rem),28rem)] flex-col gap-3', positionClass[props.position], props.className]" data-slot="notifications">
    <div v-for="notification in visible" :key="notification.id" class="pointer-events-auto w-full">
      <article :aria-label="notification.title" :class="[cardClass, tone[(notification.status ?? 'info') as NotificationStatus] ?? tone.info, 'w-full max-w-sm border p-4 shadow-lg', props.itemClassName]" data-slot="notification" :role="notification.status === 'danger' ? 'alert' : 'status'">
        <div class="flex items-start gap-3">
          <span v-if="notification.icon" aria-hidden="true" class="mt-0.5 shrink-0 text-(--inlay-accent)">{{ notification.icon }}</span>
          <div class="min-w-0 flex-1">
            <h2 class="font-medium text-(--inlay-text)">{{ notification.title }}</h2>
            <p v-if="notification.body" class="mt-1 text-sm leading-5 text-(--inlay-muted)">{{ notification.body }}</p>
            <div v-if="notification.actions?.length" class="mt-3 flex flex-wrap gap-2">
              <template v-for="action in notification.actions" :key="`${action.label}-${action.url}`">
                <a v-if="isSafeUrl(action.url)" :class="`${buttonSmallClass} px-2.5 text-xs`" :href="action.url">{{ action.label }}</a>
              </template>
            </div>
          </div>
          <button aria-label="Dismiss notification" :class="iconButtonClass" type="button" @click="dismiss(notification.id)"><span aria-hidden="true">×</span></button>
        </div>
      </article>
    </div>
  </section>
</template>
