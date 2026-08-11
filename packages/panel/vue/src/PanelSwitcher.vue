<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { Component } from 'vue'
import { isSafeUrl } from '@inlayphp/core'
import { buttonSecondaryClass, menuItemClass } from '@inlayphp/ui'
import type { PanelDirectoryEntry } from './types'

const props = withDefaults(defineProps<{
  panels: PanelDirectoryEntry[]
  currentPanelId?: string | null
  label?: string
  className?: string
  linkComponent?: Component | string
  onNavigate?: (href: string, event: MouseEvent) => void
}>(), {
  currentPanelId: null,
  label: 'Switch panel',
  className: '',
  linkComponent: 'a',
  onNavigate: undefined,
})

const open = ref(false)
const root = ref<HTMLElement | null>(null)
const trigger = ref<HTMLButtonElement | null>(null)
const menu = ref<HTMLElement | null>(null)
const focusOnOpen = ref<number | null>(null)
const current = computed(() => props.panels.find(panel => panel.id === props.currentPanelId) ?? null)
const choices = computed(() => props.panels.filter(panel => panel.id !== props.currentPanelId && isSafeUrl(panel.path)))

function focusOption(index: number): void {
  const options = menu.value?.querySelectorAll<HTMLElement>('[role="menuitem"]')
  if (!options?.length) return
  options[(index + options.length) % options.length]?.focus()
}

watch(open, (value) => {
  if (!value || focusOnOpen.value === null) return
  const index = focusOnOpen.value
  focusOnOpen.value = null
  void nextTick(() => focusOption(index))
})

function close(restoreFocus = false): void {
  open.value = false
  if (restoreFocus) void nextTick(() => trigger.value?.focus())
}

function onDocumentPointerdown(event: PointerEvent): void {
  if (open.value && !root.value?.contains(event.target as Node)) close()
}

onMounted(() => document.addEventListener('pointerdown', onDocumentPointerdown))
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointerdown))

function navigate(panel: PanelDirectoryEntry, event: MouseEvent): void {
  open.value = false
  if (props.onNavigate) {
    event.preventDefault()
    props.onNavigate(panel.path, event)
  }
}

function onTriggerKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && open.value) {
    event.preventDefault()
    close(true)
    return
  }
  if (!['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key) || choices.value.length === 0) return
  event.preventDefault()
  focusOnOpen.value = event.key === 'ArrowUp' ? choices.value.length - 1 : 0
  open.value = true
}

function onMenuKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    close(true)
    return
  }
  if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return
  event.preventDefault()
  event.stopPropagation()
  const options = menu.value?.querySelectorAll<HTMLElement>('[role="menuitem"]')
  const currentIndex = Array.from(options ?? []).indexOf(document.activeElement as HTMLElement)
  focusOption(currentIndex + (event.key === 'ArrowUp' ? -1 : 1))
}
</script>

<template>
  <div ref="root" :class="['relative', className].filter(Boolean).join(' ')" data-slot="panel-switcher">
    <button
      aria-controls="inlay-panel-switcher-options"
      :aria-expanded="open"
      :aria-label="label"
      aria-haspopup="menu"
      :class="[buttonSecondaryClass, 'border-transparent bg-transparent px-3 shadow-none hover:border-transparent hover:bg-(--inlay-panel-hover) disabled:cursor-not-allowed']"
      :disabled="choices.length === 0"
      ref="trigger"
      type="button"
      @click="open = !open"
      @keydown="onTriggerKeydown"
    >
      <img v-if="current?.brandLogo && isSafeUrl(current.brandLogo)" alt="" class="size-5 rounded object-contain" :src="current.brandLogo" />
      <span>{{ current?.label ?? label }}</span>
      <span aria-hidden="true" class="text-(--inlay-panel-muted)">⌄</span>
    </button>
    <div v-if="open && choices.length" id="inlay-panel-switcher-options" ref="menu" :aria-label="label" class="absolute left-0 top-full z-50 mt-2 grid min-w-52 gap-1 rounded-(--inlay-panel-radius) border border-(--inlay-panel-border) bg-(--inlay-panel-surface) p-1.5 shadow-lg" role="menu" @keydown="onMenuKeydown">
      <component
        :is="linkComponent"
        v-for="panel in choices"
        :key="panel.id"
        :class="[menuItemClass, 'hover:bg-(--inlay-panel-hover)']"
        :href="panel.path"
        role="menuitem"
        @click="navigate(panel, $event)"
      >
        <img v-if="panel.brandLogo && isSafeUrl(panel.brandLogo)" alt="" class="size-5 rounded object-contain" :src="panel.brandLogo" />
        <span>{{ panel.label }}</span>
      </component>
    </div>
  </div>
</template>
