<script setup lang="ts">
import { computed, ref, toRaw, watch } from 'vue'
import type { Component } from 'vue'
import { isSafeUrl } from '@inlayphp/core'
import type { PanelClassNames, PanelIconRegistry, PanelNavigationGroup, PanelNavigationItem, PanelRenderContext } from './types'
import BuiltInIcon from './BuiltInIcon.vue'
import { evaluateCondition, itemIsActive, itemIsVisible, safeAttributes, sortedGroups, sortedItems } from './utils'

const props = withDefaults(defineProps<{
  groups: PanelNavigationGroup[]
  items: PanelNavigationItem[]
  conditionValues?: Record<string, unknown>
  collapsed?: boolean
  horizontal?: boolean
  classNames?: PanelClassNames
  icons?: PanelIconRegistry
  linkComponent?: Component | string
  navigationItemRenderer?: Component
  renderContext?: PanelRenderContext
}>(), {
  conditionValues: undefined,
  collapsed: false,
  horizontal: false,
  classNames: () => ({}),
  icons: () => ({}),
  linkComponent: 'a',
  navigationItemRenderer: undefined,
  renderContext: undefined,
})

const emit = defineEmits<{ navigate: [item: PanelNavigationItem, event: MouseEvent] }>()
const locallyCollapsed = ref<Record<string, boolean>>({})
watch(() => props.groups, () => { locallyCollapsed.value = {} })
const visibleItems = computed(() => sortedItems(props.items).filter((item) => !item.group && itemIsVisible(item, props.conditionValues)))
const visibleGroups = computed(() => sortedGroups(props.groups).filter((group) => group.visible && evaluateCondition(group.visibleWhen, props.conditionValues) === true))

function groupItems(group: PanelNavigationGroup): PanelNavigationItem[] {
  return sortedItems(group.items).filter((item) => itemIsVisible(item, props.conditionValues))
}

function isGroupCollapsed(group: PanelNavigationGroup): boolean {
  return locallyCollapsed.value[group.name] ?? group.collapsed
}

function toggleGroup(group: PanelNavigationGroup): void {
  if (group.collapsible) locallyCollapsed.value[group.name] = !isGroupCollapsed(group)
}

function rawComponent(component: Component | string | undefined): Component | string | undefined {
  return component && typeof component === 'object' ? toRaw(component) : component
}

function hasUnsafeUrl(item: PanelNavigationItem): boolean {
  return item.url !== null && !isSafeUrl(item.url)
}
</script>

<template>
  <nav
    aria-label="Primary navigation"
    :class="[horizontal ? 'flex flex-wrap items-center gap-1' : 'grid gap-1', classNames.navigation]"
    data-slot="navigation"
  >
    <template v-for="item in visibleItems" :key="item.name">
      <span
        v-if="hasUnsafeUrl(item)"
        v-bind="safeAttributes(item.extraAttributes)"
        :class="['group flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm', classNames.item]"
        data-slot="navigation-label"
      >{{ item.label }}</span>
      <slot v-else name="navigation-item" :active="itemIsActive(item, conditionValues)" :collapsed="collapsed" :context="renderContext" :item="item">
        <component
          v-if="navigationItemRenderer"
          :is="rawComponent(navigationItemRenderer)"
          :active="itemIsActive(item, conditionValues)"
          :collapsed="collapsed"
          :context="renderContext"
          :item="item"
        />
        <component
          v-else
          :is="item.url ? rawComponent(linkComponent) : 'span'"
          v-bind="safeAttributes(item.extraAttributes)"
          :aria-current="itemIsActive(item, conditionValues) ? 'page' : undefined"
          :class="[
            'group flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
            itemIsActive(item, conditionValues) ? 'bg-(--inlay-panel-accent)/10 font-semibold text-(--inlay-panel-accent)' : 'text-(--inlay-panel-muted) hover:bg-(--inlay-panel-hover) hover:text-(--inlay-panel-text)',
            classNames.item,
            itemIsActive(item, conditionValues) && classNames.activeItem,
          ]"
          data-slot="navigation-item"
          :data-active="itemIsActive(item, conditionValues) || undefined"
          :href="item.url ?? undefined"
          :rel="item.openInNewTab ? 'noreferrer' : undefined"
          :target="item.openInNewTab ? '_blank' : undefined"
          @click="emit('navigate', item, $event)"
        >
          <slot v-if="item.icon" name="icon" :item="item" :name="item.icon">
            <component v-if="icons[item.icon]" :is="rawComponent(icons[item.icon])" aria-hidden="true" class="size-5 shrink-0" />
            <BuiltInIcon v-else :name="item.icon" class-name="size-5 shrink-0" />
          </slot>
          <span :class="collapsed ? 'sr-only' : 'truncate'">{{ item.label }}</span>
          <span v-if="item.badge !== null" :class="['ml-auto rounded-full bg-(--inlay-panel-badge) px-2 py-0.5 text-xs', collapsed && 'sr-only', classNames.badge]" data-slot="navigation-badge">{{ item.badge }}</span>
        </component>
      </slot>
    </template>

    <section
      v-for="group in visibleGroups"
      :key="group.name"
      v-bind="safeAttributes(group.extraAttributes)"
      :class="[horizontal ? 'relative' : 'mt-3 grid gap-1 first:mt-0', classNames.group]"
      data-slot="navigation-group"
    >
      <button
        v-if="group.collapsible"
        :aria-controls="`panel-group-${group.name}`"
        :aria-expanded="!isGroupCollapsed(group)"
        :class="['flex w-full items-center gap-2 px-3 py-2 text-xs font-semibold text-(--inlay-panel-muted)', classNames.groupLabel]"
        data-slot="navigation-group-trigger"
        type="button"
        @click="toggleGroup(group)"
      >
        <slot v-if="group.icon" name="icon" :group="group" :name="group.icon">
          <component v-if="icons[group.icon]" :is="rawComponent(icons[group.icon])" aria-hidden="true" class="size-4 shrink-0" />
        </slot>
        <span :class="collapsed ? 'sr-only' : ''">{{ group.label }}</span>
        <span aria-hidden="true" class="ml-auto">{{ isGroupCollapsed(group) ? '+' : '−' }}</span>
      </button>
      <div v-else :class="['flex items-center gap-2 px-3 py-2 text-xs font-semibold text-(--inlay-panel-muted)', collapsed && 'sr-only', classNames.groupLabel]" data-slot="navigation-group-label">
        <slot v-if="group.icon" name="icon" :group="group" :name="group.icon"><component v-if="icons[group.icon]" :is="rawComponent(icons[group.icon])" aria-hidden="true" class="size-4 shrink-0" /></slot>
        {{ group.label }}
      </div>

      <div v-show="!isGroupCollapsed(group)" :id="`panel-group-${group.name}`" :class="horizontal ? 'flex items-center gap-1' : 'grid gap-1'">
        <template v-for="item in groupItems(group)" :key="item.name">
          <span
            v-if="hasUnsafeUrl(item)"
            v-bind="safeAttributes(item.extraAttributes)"
            :class="['group flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm', classNames.item]"
            data-slot="navigation-label"
          >{{ item.label }}</span>
          <slot v-else name="navigation-item" :active="itemIsActive(item, conditionValues)" :collapsed="collapsed" :context="renderContext" :item="item">
            <component
              v-if="navigationItemRenderer"
              :is="rawComponent(navigationItemRenderer)"
              :active="itemIsActive(item, conditionValues)"
              :collapsed="collapsed"
              :context="renderContext"
              :item="item"
            />
            <component
              v-else
              :is="item.url ? rawComponent(linkComponent) : 'span'"
              v-bind="safeAttributes(item.extraAttributes)"
              :aria-current="itemIsActive(item, conditionValues) ? 'page' : undefined"
              :class="[
                'group flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                itemIsActive(item, conditionValues) ? 'bg-(--inlay-panel-accent)/10 font-semibold text-(--inlay-panel-accent)' : 'text-(--inlay-panel-muted) hover:bg-(--inlay-panel-hover) hover:text-(--inlay-panel-text)',
                classNames.item,
                itemIsActive(item, conditionValues) && classNames.activeItem,
              ]"
              data-slot="navigation-item"
              :data-active="itemIsActive(item, conditionValues) || undefined"
              :href="item.url ?? undefined"
              :rel="item.openInNewTab ? 'noreferrer' : undefined"
              :target="item.openInNewTab ? '_blank' : undefined"
              @click="emit('navigate', item, $event)"
            >
              <slot v-if="item.icon" name="icon" :item="item" :name="item.icon">
                <component v-if="icons[item.icon]" :is="rawComponent(icons[item.icon])" aria-hidden="true" class="size-5 shrink-0" />
                <BuiltInIcon v-else :name="item.icon" class-name="size-5 shrink-0" />
              </slot>
              <span :class="collapsed ? 'sr-only' : 'truncate'">{{ item.label }}</span>
              <span v-if="item.badge !== null" :class="['ml-auto rounded-full bg-(--inlay-panel-badge) px-2 py-0.5 text-xs', collapsed && 'sr-only', classNames.badge]" data-slot="navigation-badge">{{ item.badge }}</span>
            </component>
          </slot>
        </template>
      </div>
    </section>
  </nav>
</template>
