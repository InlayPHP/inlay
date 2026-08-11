<script setup lang="ts">
import { controlClass as sharedControlClass } from '@inlayphp/ui'
import { computed, ref } from 'vue'
import type { Option, SchemaComponentRenderer } from '@inlayphp/forms-vue'
import type { AbilityDefinition } from './types'

/**
 * A grouped permission picker for the ability list PHP declared.
 *
 * The options come from the field; this only groups and filters them, so a
 * permission the server did not offer can never appear here.
 */
const props = withDefaults(defineProps<{
  component: Record<string, unknown> & { label?: string; options?: Option[]; disabled?: boolean }
  path: string
  value: unknown
  update: (path: string, value: unknown) => void
  abilities?: AbilityDefinition[]
}>(), { abilities: () => [] })

type MatrixPermission = {
  name: string
  label: string
  group: string
  description: string | null
  dangerous: boolean
}

// Kept in step with the React renderer: an operation that destroys or revives a
// record is marked even when PHP did not say so.
const dangerousOperations = new Set(['delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny'])

const query = ref('')

const selected = computed(() => Array.isArray(props.value) ? props.value.map(String) : [])
const metadata = computed(() => new Map(props.abilities.map(ability => [ability.name, ability])))

const permissions = computed<MatrixPermission[]>(() => (props.component.options ?? []).map((option) => {
  const name = String(option.value)
  const ability = metadata.value.get(name)
  const segments = name.split('.')
  const operation = segments.at(-1) ?? name

  return {
    name,
    label: ability?.label ?? option.label,
    group: ability?.group || humanize(segments.slice(0, -1).join(' ') || 'Other'),
    description: ability?.description ?? null,
    dangerous: ability?.dangerous ?? dangerousOperations.has(operation),
  }
}))

const groups = computed<Array<[string, MatrixPermission[]]>>(() => {
  const needle = query.value.trim().toLocaleLowerCase()
  const filtered = permissions.value.filter(permission => !needle
    || [permission.name, permission.label, permission.group, permission.description]
      .some(candidate => candidate?.toLocaleLowerCase().includes(needle)))

  const buckets = new Map<string, MatrixPermission[]>()
  for (const permission of filtered) {
    buckets.set(permission.group, [...(buckets.get(permission.group) ?? []), permission])
  }

  return [...buckets].sort(([left], [right]) => left.localeCompare(right))
})

function setSelected(next: string[]) {
  props.update(props.path, [...new Set(next)])
}

function grantedIn(items: MatrixPermission[]): number {
  return items.filter(item => selected.value.includes(item.name)).length
}

function toggleGroup(items: MatrixPermission[], checked: boolean) {
  const names = new Set(items.map(item => item.name))
  setSelected(checked ? [...selected.value, ...names] : selected.value.filter(name => !names.has(name)))
}

function togglePermission(name: string, checked: boolean) {
  setSelected(checked ? [...selected.value, name] : selected.value.filter(candidate => candidate !== name))
}

function humanize(value: string) {
  return value.replace(/[._-]+/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/\b\w/g, letter => letter.toUpperCase())
}

// Imported rather than written out: this string had drifted from the one React
// uses, losing `aria-invalid:ring-(--inlay-danger)` so an invalid control showed no
// red ring, among other differences.
const matrixControlClass = `${sharedControlClass} block text-sm`
</script>

<template>
  <section :aria-label="component.label" class="min-w-0" data-slot="permission-matrix">
    <div class="flex flex-col gap-3 border-b border-(--inlay-border) pb-4 sm:flex-row sm:items-center sm:justify-between">
      <label class="relative block min-w-0 flex-1 sm:max-w-sm">
        <span class="sr-only">Search permissions</span>
        <svg aria-hidden="true" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-(--inlay-muted)" fill="none" height="16" viewBox="0 0 24 24" width="16"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2" /><path d="m16 16 4 4" stroke="currentColor" stroke-linecap="round" stroke-width="2" /></svg>
        <input v-model="query" :class="`${matrixControlClass} pr-3 pl-9`" placeholder="Search permissions…" type="search">
      </label>
      <p class="text-sm tabular-nums text-(--inlay-muted)"><strong class="font-semibold text-(--inlay-foreground)">{{ selected.length }}</strong> of {{ permissions.length }} selected</p>
    </div>

    <div v-if="groups.length" class="mt-5 grid gap-4 lg:grid-cols-2">
      <fieldset v-for="[group, items] in groups" :key="group" class="min-w-0 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface-muted) p-4">
        <legend class="sr-only">{{ group }}</legend>
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="font-semibold text-(--inlay-foreground)">{{ group }}</h2>
            <p class="mt-0.5 text-xs tabular-nums text-(--inlay-muted)">{{ grantedIn(items) }} of {{ items.length }} granted</p>
          </div>
          <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-(--inlay-muted)">
            <input
              :aria-label="`Select all ${group} permissions`"
              :checked="items.length > 0 && grantedIn(items) === items.length"
              class="size-4 accent-(--inlay-accent)"
              :disabled="component.disabled"
              type="checkbox"
              @change="toggleGroup(items, ($event.target as HTMLInputElement).checked)"
            >
            All
          </label>
        </div>
        <div class="mt-4 grid gap-2">
          <label
            v-for="permission in items"
            :key="permission.name"
            :class="`group flex cursor-pointer items-start gap-3 rounded-(--inlay-radius) bg-(--inlay-surface) px-3 py-2.5 ring-1 ring-(--inlay-border) transition hover:ring-(--inlay-accent)/35 ${permission.dangerous ? 'has-checked:bg-(--inlay-danger-surface) has-checked:ring-(--inlay-danger)/25' : 'has-checked:bg-(--inlay-accent)/5 has-checked:ring-(--inlay-accent)/25'}`"
          >
            <input
              :checked="selected.includes(permission.name)"
              class="mt-0.5 size-4 shrink-0 accent-(--inlay-accent)"
              :disabled="component.disabled"
              :name="path"
              type="checkbox"
              :value="permission.name"
              @change="togglePermission(permission.name, ($event.target as HTMLInputElement).checked)"
            >
            <span class="min-w-0">
              <span :class="`block text-sm font-medium ${permission.dangerous ? 'text-(--inlay-danger)' : 'text-(--inlay-foreground)'}`">{{ permission.label }}</span>
              <span class="mt-0.5 block truncate font-mono text-xs text-(--inlay-muted)">{{ permission.name }}</span>
              <span v-if="permission.description" class="mt-1 block text-xs text-(--inlay-muted)">{{ permission.description }}</span>
            </span>
          </label>
        </div>
      </fieldset>
    </div>
    <div v-else class="py-12 text-center">
      <p class="font-medium text-(--inlay-foreground)">No permissions found</p>
      <p class="mt-1 text-sm text-(--inlay-muted)">Try a different search term.</p>
    </div>
  </section>
</template>
