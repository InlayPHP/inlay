<script setup lang="ts">
import { buttonPrimaryClass, buttonSecondaryClass, controlClass } from '@inlayphp/ui'
import { computed, onBeforeUnmount, ref } from 'vue'
import type { FormComponent } from './types'
import { editImageFile } from './image-editor'
const props = defineProps<{ component: FormComponent; file: File }>()
const emit = defineEmits<{ cancel: []; save: [file: File] }>()
const ratio = ref<string | null>(props.component.imageAspectRatio ?? props.component.imageEditorAspectRatioOptions?.[0] ?? null)
const rotation = ref(0); const zoom = ref(1); const saving = ref(false); const error = ref<string | null>(null)
const source = typeof URL.createObjectURL === 'function' ? URL.createObjectURL(props.file) : ''; onBeforeUnmount(() => { if (source && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(source) })
const previewRatio = computed(() => ratio.value?.replace(':', ' / '))
async function save() { saving.value = true; error.value = null; try { emit('save', await editImageFile(props.file, { ratio: ratio.value, rotation: rotation.value, zoom: zoom.value, width: props.component.imageEditorViewportWidth, height: props.component.imageEditorViewportHeight, fill: props.component.imageEditorEmptyFillColor, circle: props.component.circleCropper })) } catch (reason) { error.value = reason instanceof Error ? reason.message : 'The edited image could not be created.' } finally { saving.value = false } }
const actionClass = `${buttonSecondaryClass} px-3 text-sm`
</script>
<template>
  <div :aria-label="`Edit ${file.name}`" aria-modal="true" class="fixed inset-0 z-50 grid place-items-center bg-(--inlay-overlay) p-4" data-slot="image-editor" role="dialog">
    <div class="grid max-h-[90dvh] w-full max-w-2xl gap-4 overflow-auto rounded-xl border border-(--inlay-border) bg-(--inlay-surface) p-5 shadow-2xl">
      <div><h2 class="text-lg font-semibold text-(--inlay-text)">Edit image</h2><p class="text-sm text-(--inlay-muted)">{{ file.name }}</p></div>
      <div :class="['mx-auto grid max-h-[55dvh] w-full max-w-xl place-items-center overflow-hidden bg-(--inlay-surface-muted)', component.circleCropper ? 'rounded-full' : 'rounded-lg']" :style="{ aspectRatio: previewRatio }"><img v-if="source" alt="Image editor preview" class="max-h-[55dvh] w-full object-contain transition-transform" :src="source" :style="{ transform: `rotate(${rotation}deg) scale(${zoom})` }"><span v-else class="text-sm text-(--inlay-muted)">Image preview unavailable</span></div>
      <div class="grid gap-3 sm:grid-cols-2"><label class="grid gap-1 text-sm"><span>Crop ratio</span><select v-model="ratio" :class="controlClass"><option v-for="option in (component.imageEditorAspectRatioOptions ?? [null, '16:9', '4:3', '1:1'])" :key="option ?? 'free'" :value="option">{{ option ?? 'Free' }}</option></select></label><label class="grid gap-1 text-sm"><span>Zoom {{ zoom.toFixed(1) }}×</span><input v-model.number="zoom" aria-label="Image zoom" max="3" min="1" step="0.1" type="range"></label></div>
      <div class="flex flex-wrap gap-2"><button :class="actionClass" type="button" @click="rotation = (rotation - 90 + 360) % 360">Rotate left</button><button :class="actionClass" type="button" @click="rotation = (rotation + 90) % 360">Rotate right</button><button :class="actionClass" type="button" @click="rotation = 0; zoom = 1">Reset</button></div>
      <p v-if="error" class="text-sm text-(--inlay-danger)" role="alert">{{ error }}</p>
      <div class="flex flex-wrap justify-end gap-2"><button :class="actionClass" :disabled="saving" type="button" @click="emit('cancel')">Cancel</button><button :class="`${buttonPrimaryClass} min-h-(--inlay-button-lg-height) px-4 py-2 font-semibold`" :disabled="saving" type="button" @click="save">{{ saving ? 'Applying…' : 'Apply crop' }}</button></div>
    </div>
  </div>
</template>
