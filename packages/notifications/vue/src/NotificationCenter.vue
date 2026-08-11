<script setup lang="ts">
import { isSafeUrl } from '@inlayphp/core'
import { buttonSmallClass, cardClass, iconButtonClass } from '@inlayphp/ui'
import { normalizeDatabaseNotifications } from '@inlayphp/notifications'
import type { DatabaseNotificationRecord, NotificationStatus } from '@inlayphp/notifications'
import { computed, ref, watch } from 'vue'

const props = withDefaults(defineProps<{
  notifications?: unknown
  onMarkRead?: (databaseId: string | number) => Promise<void> | void
  onMarkAllRead?: () => Promise<void> | void
  heading?: string
  ariaLabel?: string
  emptyMessage?: string
  class?: string
}>(), {
  notifications: () => [],
  heading: 'Notifications',
  ariaLabel: 'Notification center',
  emptyMessage: 'You’re all caught up.',
  class: '',
})

const emit = defineEmits<{
  markRead: [databaseId: string | number]
  markAllRead: []
}>()

const open = ref(false)
const readIds = ref(new Set<string>())
const records = computed(() => normalizeDatabaseNotifications(props.notifications))
const unread = computed(() => records.value.filter(record => !record.read_at && !readIds.value.has(String(record.database_id))))

const tone: Record<NotificationStatus, string> = {
  success: 'border-(--inlay-success) bg-(--inlay-success-surface)',
  info: 'border-(--inlay-info) bg-(--inlay-info-surface)',
  warning: 'border-(--inlay-warning) bg-(--inlay-warning-surface)',
  danger: 'border-(--inlay-danger) bg-(--inlay-danger-surface)',
}

watch(records, next => {
  const ids = new Set(next.map(record => String(record.database_id)))
  readIds.value = new Set([...readIds.value].filter(id => ids.has(id)))
}, { immediate: true })

async function markRead(record: DatabaseNotificationRecord) {
  if (record.read_at || readIds.value.has(String(record.database_id))) return
  readIds.value = new Set(readIds.value).add(String(record.database_id))
  emit('markRead', record.database_id)
  await props.onMarkRead?.(record.database_id)
}

async function markAllRead() {
  readIds.value = new Set([...readIds.value, ...unread.value.map(record => String(record.database_id))])
  emit('markAllRead')
  await props.onMarkAllRead?.()
}
</script>

<template>
  <div :class="`relative ${props.class}`" data-slot="notification-center">
    <button :aria-expanded="open" aria-haspopup="dialog" :aria-label="props.ariaLabel" :class="`${iconButtonClass} relative`" type="button" @click="open = !open">
      <svg aria-hidden="true" fill="none" height="18" viewBox="0 0 24 24" width="18"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Zm-8 12h4" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" /></svg>
      <span v-if="unread.length" :aria-label="`${unread.length} unread`" class="absolute -top-1 -right-1 grid min-w-5 place-items-center rounded-full bg-(--inlay-danger-surface) px-1 text-[0.65rem] font-semibold leading-5 text-(--inlay-danger)">{{ unread.length > 99 ? '99+' : unread.length }}</span>
    </button>
    <div v-if="open" :aria-label="props.ariaLabel" :class="`${cardClass} absolute top-[calc(100%+0.5rem)] right-0 z-50 w-[min(calc(100vw-2rem),24rem)] overflow-hidden border bg-(--inlay-surface) shadow-xl`" data-slot="notification-center-panel" role="dialog">
      <header class="flex items-center justify-between gap-3 border-b border-(--inlay-border) px-4 py-3">
        <div><h2 class="font-semibold text-(--inlay-text)">{{ props.heading }}</h2><p class="text-xs text-(--inlay-muted)">{{ unread.length ? `${unread.length} unread` : 'No unread notifications' }}</p></div>
        <button v-if="unread.length" :class="`${buttonSmallClass} border-0 bg-transparent px-2 text-xs text-(--inlay-accent) shadow-none`" type="button" @click="markAllRead">Mark all read</button>
      </header>
      <div class="max-h-[min(60vh,28rem)] overflow-y-auto p-2">
        <template v-if="records.length">
          <article v-for="record in records" :key="record.database_id" :class="[tone[(record.status ?? 'info') as NotificationStatus] ?? tone.info, 'mb-2 rounded-(--inlay-radius) border p-3 last:mb-0', Boolean(record.read_at) || readIds.has(String(record.database_id)) ? 'opacity-65' : '']" :data-read="Boolean(record.read_at) || readIds.has(String(record.database_id)) ? 'true' : 'false'">
            <div class="flex items-start gap-2"><div class="min-w-0 flex-1"><h3 class="font-medium text-(--inlay-text)">{{ record.title }}</h3><p v-if="record.body" class="mt-1 text-sm text-(--inlay-muted)">{{ record.body }}</p><div v-if="record.actions?.length" class="mt-2 flex flex-wrap gap-2"><template v-for="action in record.actions" :key="`${action.label}-${action.url}`"><a v-if="isSafeUrl(action.url)" :class="`${buttonSmallClass} px-2 text-xs`" :href="action.url">{{ action.label }}</a></template></div></div><button v-if="!record.read_at && !readIds.has(String(record.database_id))" :aria-label="`Mark ${record.title} as read`" :class="`${iconButtonClass} size-8 min-h-0 shrink-0 text-(--inlay-muted)`" type="button" @click="markRead(record)">✓</button></div>
          </article>
        </template>
        <p v-else class="px-3 py-8 text-center text-sm text-(--inlay-muted)">{{ props.emptyMessage }}</p>
      </div>
    </div>
  </div>
</template>
