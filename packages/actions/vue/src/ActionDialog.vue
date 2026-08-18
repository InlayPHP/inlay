<script setup lang="ts">
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import type { Component } from 'vue'
import { createActionRuntime } from '@inlayphp/actions'
import type { ActionResource, NormalizedAction } from '@inlayphp/actions'
import type { VueActionRuntime } from './useActionRuntime'
import ModalFooterAction from './ModalFooterAction.vue'

defineOptions({ name: 'ActionDialog' })
let fallbackDialogId = 0
const props = defineProps<{ controller: VueActionRuntime; formRenderer?: Component }>()
const emit = defineEmits<{ cancelParents: [target: boolean | string] }>()
const instanceId = getCurrentInstance()?.uid ?? ++fallbackDialogId
const titleId = `inlay-action-dialog-${instanceId}-heading`
const descriptionId = `inlay-action-dialog-${instanceId}-description`
const dialog = ref<HTMLElement | null>(null)
let returnFocus: HTMLElement | null = null
const nestedControllers = new Map<string, VueActionRuntime>()
const nestedUnsubscribers: Array<() => void> = []
const open = computed(() => ['mounting', 'confirming', 'executing', 'validation-error', 'failed', 'halted'].includes(props.controller.state.value.phase))
const processing = computed(() => ['mounting', 'executing'].includes(props.controller.state.value.phase))
const action = computed(() => props.controller.state.value.action)
const modal = computed(() => action.value?.modal)
const failure = computed(() => {
  if (props.controller.state.value.phase !== 'failed') return null
  const error = props.controller.state.value.error
  return error instanceof Error ? error.message : 'The action could not be completed.'
})
const widthClass = computed(() => ({ xs: 'max-w-xs', sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-xl', '2xl': 'max-w-2xl', '3xl': 'max-w-3xl', '4xl': 'max-w-4xl', '5xl': 'max-w-5xl', '6xl': 'max-w-6xl', '7xl': 'max-w-7xl', screen: 'max-w-[calc(100vw-2rem)]' }[modal.value?.width ?? 'md'] ?? 'max-w-md'))

watch(open, async (visible, wasVisible) => {
  if (visible && !wasVisible) {
    returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
    await nextTick()
    const target = modal.value?.autofocus && props.controller.state.value.phase !== 'mounting'
      ? dialog.value?.querySelector<HTMLElement>('[data-modal-role="submit"]') ?? dialog.value
      : dialog.value
    target?.focus()
  }
  if (!visible && wasVisible) {
    await nextTick()
    returnFocus?.focus()
    returnFocus = null
  }
})

watch(() => props.controller.state.value.phase, phase => {
  if (phase === 'succeeded' || phase === 'cancelled') props.controller.close()
})

function cancel(): void {
  if (processing.value) return
  props.controller.cancel()
}

function backdrop(): void {
  if (modal.value?.closeOnBackdrop) cancel()
}

function keydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && modal.value?.closeOnEscape) {
    event.preventDefault()
    cancel()
    return
  }
  if (event.key !== 'Tab') return
  const controls = [...(dialog.value?.querySelectorAll<HTMLElement>('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])') ?? [])]
  if (!controls.length) return
  const first = controls[0]!
  const last = controls.at(-1)!
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
}

function cancelParentChain(target: boolean | string): void {
  props.controller.cancel()
  props.controller.close()
  if (target === true || target !== action.value?.name) emit('cancelParents', target)
}

function nestedController(footerAction: NormalizedAction): VueActionRuntime {
  const key = footerAction.instanceKey ?? footerAction.name
  const existing = nestedControllers.get(key)
  if (existing) return existing

  const runtime = createActionRuntime(props.controller.executor)
  const state = shallowRef(runtime.state())
  const controller: VueActionRuntime = {
    runtime,
    state,
    trigger: runtime.trigger,
    confirm: runtime.confirm,
    setData: runtime.setData,
    cancel: runtime.cancel,
    close: runtime.close,
    executor: props.controller.executor,
  }
  nestedUnsubscribers.push(runtime.subscribe(value => {
    state.value = value
    if (value.phase === 'succeeded' && footerAction.cancelParentActions) {
      cancelParentChain(footerAction.cancelParentActions)
    }
  }))
  nestedControllers.set(key, controller)

  return controller
}

function openNested(footerAction: NormalizedAction): void {
  const input = props.controller.state.value.input
  void nestedController(footerAction).trigger(footerAction as unknown as ActionResource, {
    parameters: input?.parameters ?? {},
    records: [...(input?.records ?? [])],
  })
}

onBeforeUnmount(() => nestedUnsubscribers.forEach(unsubscribe => unsubscribe()))
</script>

<template>
  <Teleport to="body">
    <div v-if="open && action && modal" :class="['fixed inset-0 z-50 bg-(--inlay-overlay) backdrop-blur-[2px]', modal.slideOver ? 'flex justify-end' : 'grid place-items-center p-4']" data-slot="action-dialog-backdrop" @click.self="backdrop">
      <section
        ref="dialog"
        :aria-describedby="modal.description ? descriptionId : undefined"
        :aria-labelledby="titleId"
        :aria-busy="processing"
        aria-modal="true"
        :class="['w-full overflow-y-auto bg-(--inlay-surface) text-(--inlay-foreground) shadow-2xl ring-1 ring-(--inlay-border)', widthClass, modal.slideOver ? 'h-dvh max-h-dvh rounded-none' : 'max-h-[calc(100dvh-2rem)] rounded-(--inlay-radius)', modal.alignment === 'center' && 'text-center']"
        :data-presentation="modal.slideOver ? 'slide-over' : 'modal'"
        data-slot="action-dialog"
        role="dialog"
        tabindex="-1"
        @keydown="keydown"
      >
        <header :class="['relative border-b border-(--inlay-border) p-5', modal.stickyHeader ? 'sticky top-0 z-10 bg-(--inlay-surface) pb-4' : '']" data-slot="action-dialog-header">
          <span v-if="modal.icon" aria-hidden="true" class="mb-3 inline-flex size-10 items-center justify-center rounded-full bg-(--inlay-surface-muted)" :data-color="modal.iconColor ?? undefined">{{ modal.icon }}</span>
          <h2 :id="titleId" class="text-lg font-semibold">{{ modal.heading }}</h2>
          <p v-if="modal.description" :id="descriptionId" class="mt-2 text-sm text-(--inlay-muted)">{{ modal.description }}</p>
          <button aria-label="Close dialog" class="absolute right-5 top-5 inline-flex size-11 items-center justify-center rounded-(--inlay-radius) border border-transparent text-(--inlay-muted) hover:border-(--inlay-border) hover:bg-(--inlay-surface-muted) hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" data-modal-role="close" :disabled="processing" type="button" @click="cancel">×</button>
        </header>
        <div class="px-5 pb-5" data-slot="action-dialog-body">
          <p v-if="controller.state.value.phase === 'mounting'" class="mt-4 text-sm text-(--inlay-muted)" role="status">Loading form…</p>
          <div v-else-if="controller.state.value.form" class="mt-4 text-left" data-slot="action-dialog-content">
            <component :is="formRenderer" v-if="formRenderer" :controller="controller" />
            <slot v-else :controller="controller" />
          </div>
          <div v-if="Object.keys(controller.state.value.validationErrors).length" class="mt-4 rounded-md bg-(--inlay-danger-surface) p-3 text-sm text-(--inlay-danger)" role="alert">
            <ul><li v-for="(messages, path) in controller.state.value.validationErrors" :key="path">{{ messages[0] }}</li></ul>
          </div>
          <p v-else-if="failure" class="mt-4 rounded-md bg-(--inlay-danger-surface) p-3 text-sm text-(--inlay-danger)" role="alert">{{ failure }}</p>
          <p v-if="controller.state.value.phase === 'halted' && controller.state.value.message" class="mt-4 rounded-md bg-(--inlay-warning-surface) p-3 text-sm text-(--inlay-warning)" role="status">{{ controller.state.value.message }}</p>
          <p v-if="processing" class="mt-4 text-sm text-(--inlay-muted)" role="status">Processing…</p>
        </div>
        <footer :class="['flex gap-2 border-t border-(--inlay-border) px-5 pb-5 pt-4', modal.stickyFooter ? 'sticky bottom-0 z-10 bg-(--inlay-surface)' : '', modal.alignment === 'center' ? 'justify-center' : 'justify-end']" data-slot="action-dialog-footer">
          <ModalFooterAction v-if="modal.cancelAction" :action="modal.cancelAction" :disabled="processing" modal-role="cancel" @activate="cancel" />
          <ModalFooterAction v-for="footerAction in modal.extraFooterActions" :key="footerAction.instanceKey ?? footerAction.name" :action="footerAction" :disabled="processing" :modal-role="footerAction.modalFooterMode === 'action' ? 'extra-action' : 'extra-submit'" @activate="footerAction.modalFooterMode === 'action' ? openNested(footerAction) : controller.confirm(footerAction.arguments)" />
          <ModalFooterAction v-if="modal.submitAction" :action="modal.submitAction" :disabled="processing" :processing="processing" modal-role="submit" @activate="controller.confirm(modal.submitAction?.arguments)" />
        </footer>
      </section>
    </div>
    <template v-for="footerAction in modal?.extraFooterActions ?? []" :key="`nested-${footerAction.instanceKey ?? footerAction.name}`">
      <ActionDialog
        v-if="footerAction.modalFooterMode === 'action'"
        :controller="nestedController(footerAction)"
        :form-renderer="formRenderer"
        @cancel-parents="cancelParentChain"
      />
    </template>
  </Teleport>
</template>
