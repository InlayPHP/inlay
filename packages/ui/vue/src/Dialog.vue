<script setup lang="ts">
import { getCurrentInstance, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { dialogClass } from '@inlayphp/ui'

defineOptions({ name: 'InlayDialog' })

const props = withDefaults(defineProps<{
  open: boolean
  title: string
  description?: string
  closeOnEscape?: boolean
  closeOnBackdrop?: boolean
  className?: string
  backdropClassName?: string
}>(), {
  description: undefined,
  closeOnEscape: true,
  closeOnBackdrop: true,
  className: '',
  backdropClassName: '',
})

const emit = defineEmits<{
  'update:open': [open: boolean]
}>()

const dialog = ref<HTMLElement | null>(null)
let fallbackDialogId = 0
const instanceId = getCurrentInstance()?.uid ?? ++fallbackDialogId
const titleId = `inlay-dialog-${instanceId}-title`
const descriptionId = `${titleId}-description`
let returnFocus: HTMLElement | null = null

function focusable(container: HTMLElement): HTMLElement[] {
  return [...container.querySelectorAll<HTMLElement>(
    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
  )]
}

function focusInitial(): void {
  const target = focusable(dialog.value ?? document.body)[0] ?? dialog.value
  target?.focus()
}

async function openDialog(): Promise<void> {
  returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
  await nextTick()
  focusInitial()
}

watch(() => props.open, async (open, wasOpen) => {
  if (open && !wasOpen) {
    await openDialog()
  }
  if (!open && wasOpen) {
    await nextTick()
    returnFocus?.focus()
    returnFocus = null
  }
}, { flush: 'post' })

onMounted(() => {
  if (props.open) void openDialog()
})

function close(): void {
  emit('update:open', false)
}

function backdrop(event: MouseEvent): void {
  if (props.closeOnBackdrop && event.target === event.currentTarget) close()
}

function keydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && props.closeOnEscape) {
    event.preventDefault()
    close()
    return
  }
  if (event.key !== 'Tab') return

  const controls = focusable(dialog.value ?? (event.currentTarget as HTMLElement))
  if (!controls.length) {
    event.preventDefault()
    dialog.value?.focus()
    return
  }
  const first = controls[0]!
  const last = controls.at(-1)!
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

onBeforeUnmount(() => {
  returnFocus = null
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" :class="['fixed inset-0 z-50 grid place-items-center bg-(--inlay-overlay) p-4 backdrop-blur-[2px]', backdropClassName]" data-slot="dialog-backdrop" @mousedown="backdrop">
      <div
        ref="dialog"
        :aria-describedby="description ? descriptionId : undefined"
        :aria-labelledby="titleId"
        aria-modal="true"
        :class="[dialogClass, 'w-full max-w-lg', className]"
        data-slot="dialog"
        role="dialog"
        tabindex="-1"
        @keydown="keydown"
      >
        <header data-slot="dialog-header">
          <h2 :id="titleId" class="text-lg font-semibold" data-slot="dialog-title">{{ title }}</h2>
          <p v-if="description" :id="descriptionId" class="mt-2 text-sm text-(--inlay-muted)" data-slot="dialog-description">{{ description }}</p>
        </header>
        <div class="mt-5" data-slot="dialog-body"><slot /></div>
      </div>
    </div>
  </Teleport>
</template>
