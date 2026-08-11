<script setup lang="ts">
import { controlClass as sharedControlClass } from '@inlayphp/ui'
import { computed, ref, toRaw } from 'vue'
import type { Component, CSSProperties } from 'vue'
import type { CellPresentation, Column, TableRendererRegistries, TableRenderers, TableRow } from './types'
import { safeUrl } from './url'
import NamedIcon from './NamedIcon.vue'
const props = withDefaults(defineProps<{ column: Column; row: TableRow; disabled?: boolean; error?: string | null; renderers?: TableRenderers; registries?: TableRendererRegistries }>(), { disabled: false, error: null })
const emit = defineEmits<{ change: [value: unknown] }>()
const presentation = computed(() => ((props.row.__inlay as { columns?: Record<string, CellPresentation> } | undefined)?.columns?.[props.column.name]))
const raw = computed(() => presentation.value ? presentation.value.state : props.column.name.split('.').reduce<unknown>((value, key) => value && typeof value === 'object' ? (value as TableRow)[key] : undefined, props.row))
const hasFormattedState = computed(() => presentation.value !== undefined && Object.prototype.hasOwnProperty.call(presentation.value, 'formattedState'))
const displayRaw = computed(() => hasFormattedState.value ? presentation.value?.formattedState : raw.value)
// Relative time is computed in the browser so it stays correct while a page is
// left open, rather than being frozen when the payload was built.
function relativeTime(input: unknown): string | null {
  const parsed = new Date(String(input))
  if (Number.isNaN(parsed.getTime())) return null
  const seconds = Math.round((parsed.getTime() - Date.now()) / 1000)
  const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]]
  const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size || unit === 'second') return formatter.format(Math.round(seconds / size), unit)
  }
  return null
}
function formatValue(input: unknown, alreadyFormatted = false): unknown { if (alreadyFormatted) return input ?? ''; if (props.column.since && input) return relativeTime(input) ?? input; if (props.column.money && typeof input === 'number') return new Intl.NumberFormat(undefined, { style: 'currency', currency: props.column.currency ?? 'USD' }).format(input); if (props.column.numeric && typeof input === 'number') return new Intl.NumberFormat().format(input); if (props.column.dateFormat && input) return new Intl.DateTimeFormat().format(new Date(String(input))); return input ?? '' }
const value = computed(() => formatValue(displayRaw.value, hasFormattedState.value))
const truncated = computed(() => {
  if (wordLimit.value) {
    const words = String(value.value).split(/\s+/)

    return words.slice(0, wordLimit.value).join(' ') + (words.length > wordLimit.value ? wordLimitEnd.value : '')
  }

  return characterLimit.value && String(value.value).length > characterLimit.value
    ? `${String(value.value).slice(0, characterLimit.value)}${characterLimitEnd.value}`
    : String(value.value)
})
const content = computed(() => empty.value ? truncated.value : `${prefix.value ?? ''}${truncated.value}${suffix.value ?? ''}`)
const renderer = computed(() => props.renderers?.column?.[props.column.type] ?? (props.registries?.column ? toRaw(props.registries.column).get(props.column.type) : undefined))
const component = computed(() => renderer.value && typeof renderer.value === 'object' ? toRaw(renderer.value) : renderer.value)
// Imported rather than written out: this string had drifted from the one React
// uses, losing `aria-invalid:ring-(--inlay-danger)` so an invalid control showed no
// red ring, among other differences.
const cellControlClass = `${sharedControlClass} sm:py-1.5 sm:text-sm`
const hrefTemplate = computed(() => presentation.value && Object.prototype.hasOwnProperty.call(presentation.value, 'url') ? presentation.value.url : props.column.url)
const href = computed(() => safeUrl(hrefTemplate.value?.replace(/\{([^}]+)\}/g, (_, key) => encodeURIComponent(String(props.row[key] ?? '')))))
const openUrlInNewTab = computed(() => presentation.value?.openUrlInNewTab ?? props.column.openUrlInNewTab)
const empty = computed(() => displayRaw.value === null || displayRaw.value === undefined || displayRaw.value === '')
const display = computed(() => empty.value && props.column.placeholder ? props.column.placeholder : content.value)
const richText = computed(() => presentation.value?.html ?? props.column.html ?? presentation.value?.markdown ?? props.column.markdown)
const description = computed(() => presentation.value?.description ?? props.column.description)
const tooltip = computed(() => presentation.value?.tooltip ?? props.column.tooltip)
const copyable = computed(() => presentation.value?.copyable ?? props.column.copyable)
const copyMessage = computed(() => presentation.value?.copyMessage ?? props.column.copyMessage ?? 'Copied')
const copyMessageDuration = computed(() => presentation.value?.copyMessageDuration ?? props.column.copyMessageDuration ?? 2000)
const copyValue = computed(() => presentation.value && Object.prototype.hasOwnProperty.call(presentation.value, 'copyableState') ? presentation.value.copyableState : raw.value)
const copied = ref(false)
const listExpanded = ref(false)
const imageFallbackUrl = computed(() => presentation.value && Object.prototype.hasOwnProperty.call(presentation.value, 'fallbackUrl') ? presentation.value.fallbackUrl : props.column.fallbackUrl)
const imageAlt = computed(() => presentation.value?.alt ?? props.column.alt ?? props.column.label)
const imageUrls = computed(() => (Array.isArray(raw.value) ? raw.value : [raw.value ?? imageFallbackUrl.value]).map(url => safeUrl(String(url ?? ''))).filter((url): url is string => Boolean(url)))
const imageCircular = computed(() => presentation.value?.circular ?? props.column.circular)
const imageSquare = computed(() => presentation.value?.square ?? props.column.square)
const imageStacked = computed(() => presentation.value?.stacked ?? props.column.stacked)
const imageSize = computed(() => presentation.value?.size ?? props.column.size ?? 40)
const imageWidth = computed(() => presentation.value?.width ?? props.column.width ?? imageSize.value)
const imageHeight = computed(() => presentation.value?.height ?? props.column.height ?? imageSize.value)
const imageRing = computed(() => presentation.value?.ring ?? props.column.ring ?? 3)
const imageOverlap = computed(() => presentation.value?.overlap ?? props.column.overlap ?? 4)
const imageLimit = computed(() => presentation.value?.limit ?? props.column.limit)
const imageWrap = computed(() => presentation.value?.wrap ?? props.column.wrap)
const imageShowRemaining = computed(() => presentation.value?.limitedRemainingText ?? props.column.limitedRemainingText)
const visibleImages = computed(() => imageLimit.value ? imageUrls.value.slice(0, imageLimit.value) : imageUrls.value)
const remainingImages = computed(() => imageUrls.value.length - visibleImages.value.length)
const textItems = computed(() => Array.isArray(displayRaw.value) ? displayRaw.value.map(item => formatValue(item, hasFormattedState.value)).map(String) : [])
const visibleTextItems = computed(() => listLimit.value && !listExpanded.value ? textItems.value.slice(0, listLimit.value) : textItems.value)
const remainingTextItems = computed(() => textItems.value.length - visibleTextItems.value.length)
const textColor = computed(() => presentation.value?.color ?? props.column.color)
const textIcon = computed(() => presentation.value?.icon ?? props.column.icon)
const textIconColor = computed(() => presentation.value?.iconColor ?? props.column.iconColor)
const badge = computed(() => presentation.value?.badge ?? props.column.badge)
const bulleted = computed(() => presentation.value?.bulleted ?? props.column.bulleted)
const listWithLineBreaks = computed(() => presentation.value?.listWithLineBreaks ?? props.column.listWithLineBreaks)
const listLimit = computed(() => presentation.value?.listLimit ?? props.column.listLimit)
const expandableLimitedList = computed(() => presentation.value?.expandableLimitedList ?? props.column.expandableLimitedList)
const wrap = computed(() => presentation.value?.wrap ?? props.column.wrap)
const characterLimit = computed(() => presentation.value?.limit ?? props.column.limit)
const characterLimitEnd = computed(() => presentation.value?.limitEnd ?? props.column.limitEnd ?? '…')
const wordLimit = computed(() => presentation.value?.words ?? props.column.words)
const wordLimitEnd = computed(() => presentation.value?.wordsEnd ?? props.column.wordsEnd ?? '…')
const prefix = computed(() => presentation.value?.prefix ?? props.column.prefix)
const suffix = computed(() => presentation.value?.suffix ?? props.column.suffix)
const textSize = computed(() => presentation.value?.textSize ?? props.column.textSize)
const lineClamp = computed(() => presentation.value?.lineClamp ?? props.column.lineClamp)
const textClasses = computed(() => `${textSizeClass(textSize.value)} ${textWeightClass(props.column.fontWeight)} ${textFamilyClass(props.column.fontFamily)} ${semanticTextClass(textColor.value)}`)
const textStyle = computed(() => semanticTextStyle(textColor.value, Boolean(badge.value)))
const contentClasses = computed(() => `${wrap.value ? 'min-w-0 max-w-full whitespace-normal break-words' : 'block min-w-0 max-w-full flex-1 truncate'} ${lineClamp.value ? 'overflow-hidden' : ''}`)
const contentStyle = computed<CSSProperties | undefined>(() => lineClamp.value ? { display: '-webkit-box', WebkitBoxOrient: 'vertical', WebkitLineClamp: lineClamp.value } : undefined)
function imageStyle(index: number) { return { boxShadow: imageStacked.value && imageRing.value > 0 ? `0 0 0 ${imageRing.value}px var(--inlay-surface)` : undefined, marginInlineStart: imageStacked.value && index > 0 ? `${-imageOverlap.value * 2}px` : undefined, zIndex: visibleImages.value.length - index } }
async function copy() {
  try {
    await navigator.clipboard.writeText(String(copyValue.value ?? ''))
    copied.value = true
    window.setTimeout(() => { copied.value = false }, copyMessageDuration.value)
  } catch {
    copied.value = false
  }
}
function badgeColor() { const color = props.column.colors?.[String(raw.value)]; return color === 'success' ? 'bg-(--inlay-success-surface) text-(--inlay-success)' : color === 'danger' ? 'bg-(--inlay-danger-surface) text-(--inlay-danger)' : 'bg-(--inlay-surface-muted) text-(--inlay-text)' }
function semanticTextClass(color?: string | null) { return color === 'primary' ? 'text-(--inlay-accent)' : color === 'danger' ? 'text-(--inlay-danger)' : color === 'info' ? 'text-(--inlay-info)' : color === 'success' ? 'text-(--inlay-success)' : color === 'warning' ? 'text-(--inlay-warning)' : color === 'gray' ? 'text-(--inlay-muted)' : '' }
function textBadgeClass(color?: string | null) { return color === 'primary' ? 'bg-(--inlay-accent)/10 text-(--inlay-accent)' : color === 'danger' ? 'bg-(--inlay-danger-surface) text-(--inlay-danger)' : color === 'info' ? 'bg-(--inlay-info-surface) text-(--inlay-info)' : color === 'success' ? 'bg-(--inlay-success-surface) text-(--inlay-success)' : color === 'warning' ? 'bg-(--inlay-warning-surface) text-(--inlay-warning)' : color === 'gray' || !color ? 'bg-(--inlay-surface-muted) text-(--inlay-text)' : '' }
function semanticTextStyle(color?: string | null, badge = false) { if (!color || ['primary', 'danger', 'info', 'success', 'warning', 'gray'].includes(color)) return undefined; return badge ? { color: `var(--inlay-color-${color})`, backgroundColor: `var(--inlay-color-${color}-soft)` } : { color: `var(--inlay-color-${color})` } }
function textSizeClass(size?: Column['textSize']) { return size === 'small' ? 'text-xs' : size === 'large' ? 'text-base' : 'text-sm' }
function textWeightClass(weight?: Column['fontWeight']) { return weight === 'light' ? 'font-light' : weight === 'medium' ? 'font-medium' : weight === 'semibold' ? 'font-semibold' : weight === 'bold' ? 'font-bold' : 'font-normal' }
function textFamilyClass(family?: Column['fontFamily']) { return family === 'serif' ? 'font-serif' : family === 'mono' ? 'font-mono' : 'font-sans' }
</script>
<template>
  <component v-if="component" :is="component as Component" :column="column" :disabled="disabled" :error="error" :row="row" :raw-value="raw" :value="value" :on-change="(next: unknown) => emit('change', next)" />
  <div v-else-if="column.type === 'image-column'" :aria-label="column.label" :class="`flex items-center ${imageWrap ? 'flex-wrap' : 'flex-nowrap'} ${imageStacked ? 'isolate' : 'gap-2'}`" :role="imageUrls.length > 1 ? 'group' : undefined">
    <img v-for="(url, index) in visibleImages" :key="`${url}-${index}`" :alt="imageUrls.length > 1 ? `${imageAlt} ${index + 1}` : imageAlt" :class="`object-cover ${imageCircular ? 'rounded-full' : imageSquare ? 'rounded-none' : 'rounded-md'}`" :height="imageHeight" loading="lazy" :src="url" :style="imageStyle(index)" :width="imageSquare ? imageHeight : imageWidth">
    <span v-if="remainingImages > 0 && imageShowRemaining" :aria-label="`${remainingImages} more images`" class="text-xs font-medium text-(--inlay-muted)">+{{ remainingImages }}</span>
  </div>
  <span v-else-if="column.type === 'color-column'" :aria-label="`${column.label}: ${value}`" class="inline-block size-6 rounded-sm ring-1 ring-(--inlay-border)" :style="{ backgroundColor: String(raw ?? 'transparent') }" />
  <span v-else-if="column.type === 'boolean-column' || column.type === 'icon-column'" :aria-label="Boolean(raw) ? 'Yes' : 'No'" :class="Boolean(raw) ? 'text-(--inlay-success)' : 'text-(--inlay-danger)'"><NamedIcon :fallback="Boolean(raw) ? '✓' : '×'" :name="Boolean(raw) ? (column.trueIcon ?? 'check') : (column.falseIcon ?? 'x')" :registries="registries" :renderers="renderers" /></span>
  <span v-else-if="column.type === 'badge-column'" :class="`rounded-full px-2 py-1 text-base font-medium sm:text-sm ${badgeColor()}`">{{ column.labels?.[String(raw)] ?? value }}</span>
  <select v-else-if="column.type === 'select-column'" :aria-invalid="Boolean(error)" :aria-label="`${column.label} for ${row.id}`" :class="cellControlClass" :disabled="disabled" :value="String(raw ?? '')" @change="emit('change', ($event.target as HTMLSelectElement).value)"><option v-for="option in column.options" :key="option.value" :value="option.value">{{ option.label }}</option></select>
  <input v-else-if="column.type === 'toggle-column' || column.type === 'checkbox-column'" :aria-invalid="Boolean(error)" :aria-label="`${column.label} for ${row.id}`" :checked="Boolean(raw)" class="size-5 accent-(--inlay-accent) sm:size-4" :disabled="disabled" type="checkbox" @change="emit('change', ($event.target as HTMLInputElement).checked)">
  <input v-else-if="column.type === 'text-input-column'" :aria-invalid="Boolean(error)" :aria-label="`${column.label} for ${row.id}`" :class="cellControlClass" :disabled="disabled" :type="column.inputType ?? 'text'" :value="String(raw ?? '')" @input="emit('change', ($event.target as HTMLInputElement).value)">
  <span v-else-if="Array.isArray(displayRaw) && listWithLineBreaks" class="inline-grid min-w-0 gap-1" :title="tooltip ?? undefined">
    <span v-if="description && column.descriptionPosition === 'above'" class="text-xs text-(--inlay-muted)">{{ description }}</span>
    <ul :class="bulleted ? 'list-inside list-disc space-y-0.5' : 'grid list-none gap-0.5'"><li v-for="(item, index) in visibleTextItems" :key="index" :class="`${bulleted ? '' : 'flex items-start gap-1.5'} ${textClasses}`" :data-color="textColor ?? undefined" :style="textStyle"><span :class="`${badge ? `${textBadgeClass(textColor)} rounded-full px-2 py-0.5` : ''} inline-flex items-start gap-1.5`"><NamedIcon v-if="textIcon && column.iconPosition !== 'after'" :icon-class="`shrink-0 ${semanticTextClass(textIconColor)}`" fallback="◆" :icon-style="semanticTextStyle(textIconColor)" :name="textIcon" :registries="registries" :renderers="renderers" /><span :class="wrap ? 'whitespace-normal' : 'whitespace-nowrap'">{{ item }}</span><NamedIcon v-if="textIcon && column.iconPosition === 'after'" :icon-class="`shrink-0 ${semanticTextClass(textIconColor)}`" fallback="◆" :icon-style="semanticTextStyle(textIconColor)" :name="textIcon" :registries="registries" :renderers="renderers" /></span></li></ul>
    <button v-if="listLimit && expandableLimitedList && textItems.length > listLimit" :aria-expanded="listExpanded" class="justify-self-start text-xs font-medium text-(--inlay-accent) hover:underline" type="button" @click="listExpanded = !listExpanded">{{ listExpanded ? 'Show less' : `Show ${remainingTextItems} more` }}</button>
    <span v-else-if="remainingTextItems > 0" class="text-xs text-(--inlay-muted)">+{{ remainingTextItems }} more</span>
    <span v-if="description && column.descriptionPosition !== 'above'" class="text-xs text-(--inlay-muted)">{{ description }}</span>
  </span>
  <span v-else class="grid min-w-0 w-full max-w-full gap-0.5 overflow-hidden" :title="tooltip ?? undefined">
    <span v-if="description && column.descriptionPosition === 'above'" class="truncate text-xs text-(--inlay-muted)">{{ description }}</span>
      <span class="inline-flex min-w-0 max-w-full items-center gap-1.5 overflow-hidden">
        <span :class="`${textClasses} ${badge ? `${textBadgeClass(textColor)} rounded-full px-2 py-0.5` : ''} inline-flex min-w-0 max-w-full flex-1 items-center gap-1.5 overflow-hidden`" :data-color="textColor ?? undefined" :style="textStyle"><NamedIcon v-if="textIcon && column.iconPosition !== 'after'" :icon-class="`shrink-0 ${semanticTextClass(textIconColor)}`" fallback="◆" :icon-style="semanticTextStyle(textIconColor)" :name="textIcon" :registries="registries" :renderers="renderers" /><a v-if="href" :class="`${textColor ? 'text-inherit' : 'text-(--inlay-accent)'} ${contentClasses} underline decoration-current/30 underline-offset-2`" :href="href" :rel="openUrlInNewTab ? 'noreferrer' : undefined" :style="contentStyle" :target="openUrlInNewTab ? '_blank' : undefined">{{ richText && !empty ? '' : display }}<span v-if="richText && !empty" v-html="String(display)" /></a><span v-else :class="`${empty && column.placeholder ? 'text-(--inlay-muted)' : ''} ${contentClasses}`" :style="contentStyle">{{ richText && !empty ? '' : display }}<span v-if="richText && !empty" v-html="String(display)" /></span><NamedIcon v-if="textIcon && column.iconPosition === 'after'" :icon-class="`shrink-0 ${semanticTextClass(textIconColor)}`" fallback="◆" :icon-style="semanticTextStyle(textIconColor)" :name="textIcon" :registries="registries" :renderers="renderers" /></span>
      <button v-if="copyable" :aria-label="`Copy ${column.label}`" class="shrink-0 rounded-sm p-1 text-(--inlay-muted) hover:bg-(--inlay-hover) hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" :title="copyMessage" type="button" @click="copy"><span aria-hidden="true">⎘</span></button>
    </span>
    <span v-if="description && column.descriptionPosition !== 'above'" class="truncate text-xs text-(--inlay-muted)">{{ description }}</span>
    <span v-if="copied" aria-live="polite" class="text-xs text-(--inlay-success)" role="status">{{ copyMessage }}</span>
  </span>
</template>
