<script setup lang="ts">
import { computed, ref } from 'vue'
import type { CSSProperties } from 'vue'
import type { InfolistClassNames, InfolistComponent, InfolistIconRenderer, InfolistRendererRegistries } from './types'
import { safeUrl } from './url'
import ImageEntryValue from './ImageEntryValue.vue'
import NamedIcon from './NamedIcon.vue'

/** `classNames.empty` styles a placeholder in React, so it must reach these spans too. */
const props = withDefaults(defineProps<{ component: InfolistComponent; value: unknown; emptyValue?: string; classNames?: InfolistClassNames; icons?: Record<string, InfolistIconRenderer>; registries?: InfolistRendererRegistries }>(), { emptyValue: '—', classNames: () => ({}), icons: () => ({}) })
const copied = ref(false)
const copyStatus = ref('')
const listExpanded = ref(false)
const empty = computed(() => props.value == null || props.value === '' || (Array.isArray(props.value) && props.value.length === 0) || (typeof props.value === 'object' && !Array.isArray(props.value) && Object.keys(props.value).length === 0))
const keyValues = computed(() => props.value && typeof props.value === 'object' && !Array.isArray(props.value) ? Object.entries(props.value) : [])
function keyValueText(value: unknown): string {
  if (value == null) return ''
  if (typeof value !== 'object') return String(value)
  try { return JSON.stringify(value) }
  catch { return String(value) }
}
const isList = computed(() => props.component.list || props.component.listWithLineBreaks || props.component.bulleted)
const listItems = computed(() => {
  if (!isList.value) return []
  if (Array.isArray(props.value)) return props.value.map(String)

  return String(props.value).split(props.component.separator ?? ',').map(item => item.trim()).filter(Boolean)
})
const visibleListItems = computed(() => listExpanded.value ? listItems.value : listItems.value.slice(0, props.component.listLimit ?? listItems.value.length))
const remainingListItems = computed(() => Math.max(0, listItems.value.length - (props.component.listLimit ?? listItems.value.length)))
const codeSource = computed(() => {
  if (typeof props.value === 'object' && props.value !== null) {
    if (props.component.highlightedSource) {
      try {
        if (JSON.stringify(JSON.parse(props.component.highlightedSource)) === JSON.stringify(props.value)) return props.component.highlightedSource
      } catch {
        // The server source is not equivalent JSON, so derive an escaped fallback.
      }
    }
    try {
      return JSON.stringify(props.value, null, 4)
    } catch {
      return String(props.value)
    }
  }

  return String(props.value ?? '')
})
const highlightedCode = computed(() => props.component.highlightedSource === codeSource.value ? props.component.highlightedHtml : null)
const richContent = computed(() => props.component.contentFromState ? String(props.value ?? '') : props.component.content ?? '')
const richPlainText = computed(() => props.component.contentFromState ? plainTextFromHtml(richContent.value) : props.component.plainContent ?? String(props.value ?? ''))
const clampStyle = computed<CSSProperties | undefined>(() => props.component.lineClamp ? {
  display: '-webkit-box',
  WebkitBoxOrient: 'vertical',
  WebkitLineClamp: props.component.lineClamp,
  overflow: 'hidden',
} : undefined)
const iconColorClass = computed(() => ({
  neutral: 'text-(--inlay-infolist-muted)',
  primary: 'text-(--inlay-infolist-accent)',
  info: 'text-(--inlay-infolist-info)',
  success: 'text-(--inlay-infolist-success)',
  warning: 'text-(--inlay-infolist-warning)',
  danger: 'text-(--inlay-infolist-danger)',
}[props.component.iconColor ?? ''] ?? ''))
const iconColorStyle = computed(() => iconColorClass.value ? undefined : { color: props.component.iconColor ?? undefined })
const iconEntryStates = computed(() => Array.isArray(props.value) ? props.value : [props.value])
function iconEntryName(state: unknown): string | null {
  const configured = props.component.icon ?? (props.component.boolean ? state ? props.component.trueIcon ?? 'check-circle' : props.component.falseIcon ?? 'x-circle' : String(state ?? ''))
  return configured === false || configured == null || configured === '' ? null : configured
}
function iconEntryColor(state: unknown) { return props.component.color ?? (props.component.boolean ? state ? props.component.trueColor : props.component.falseColor : null) }
function iconEntryTone(state: unknown) { return ({ neutral: 'text-(--inlay-infolist-muted)', primary: 'text-(--inlay-infolist-accent)', info: 'text-(--inlay-infolist-info)', success: 'text-(--inlay-infolist-success)', warning: 'text-(--inlay-infolist-warning)', danger: 'text-(--inlay-infolist-danger)' }[iconEntryColor(state) ?? ''] ?? '') }
function iconEntrySize() { return ({ xs: 'text-xs', 'extra-small': 'text-xs', sm: 'text-sm', small: 'text-sm', md: 'text-base', medium: 'text-base', lg: 'text-lg', large: 'text-lg', xl: 'text-xl', 'extra-large': 'text-xl', '2xl': 'text-2xl' }[typeof props.component.size === 'string' ? props.component.size : 'md'] ?? 'text-base') }
function iconEntryLabel(state: unknown) { return `${props.component.label}: ${props.component.boolean ? state ? 'Yes' : 'No' : String(state)}` }
function iconEntryFallback(name: string) { return name === 'check-circle' ? '✓' : name === 'x-circle' ? '✕' : name }

// Relative time is computed in the browser so it stays correct while a page is
// left open, matching how table columns render the same value.
function relativeTime(value: unknown): string | null {
  const parsed = new Date(String(value))
  if (Number.isNaN(parsed.getTime())) return null
  const seconds = Math.round((parsed.getTime() - Date.now()) / 1000)
  const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]]
  const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size || unit === 'second') return formatter.format(Math.round(seconds / size), unit)
  }
  return null
}

function formatted() {
  if (empty.value) return props.component.placeholder ?? props.emptyValue
  if (props.component.since && props.value) return relativeTime(props.value) ?? String(props.value)
  if (Array.isArray(props.value)) return props.value.map(String).join(props.component.separator ?? ', ')
  if (typeof props.value === 'boolean') return props.value ? 'Yes' : 'No'
  if (props.component.format?.type === 'number' || props.component.format?.type === 'money') {
    const numeric = Number(props.value) / (props.component.format.type === 'money' ? props.component.format.divideBy ?? 1 : 1)
    if (!Number.isNaN(numeric)) return new Intl.NumberFormat(props.component.format.locale ?? undefined, {
      style: props.component.format.type === 'money' ? 'currency' : 'decimal',
      currency: props.component.format.type === 'money' ? props.component.format.currency : undefined,
      minimumFractionDigits: props.component.format.decimalPlaces,
      maximumFractionDigits: props.component.format.decimalPlaces,
    }).format(numeric)
  }
  if (props.component.format?.type === 'date') {
    const date = new Date(String(props.value))
    if (!Number.isNaN(date.getTime())) return formatDate(date, props.component.format.format, props.component.format.timezone)
  }
  const result = String(props.value)
  if (props.component.words) {
    const words = result.split(/\s+/)

    return words.slice(0, props.component.words).join(' ') + (words.length > props.component.words ? props.component.wordsEnd ?? '…' : '')
  }

  return props.component.limit && result.length > props.component.limit ? `${result.slice(0, props.component.limit)}${props.component.limitEnd ?? '…'}` : result
}
const text = computed(() => `${props.component.prefix ?? ''}${formatted()}${props.component.suffix ?? ''}`)
const href = computed(() => safeUrl(props.component.url ? props.component.urlValue ?? (typeof props.component.url === 'string' ? props.component.url : String(props.value)) : null))
function formatDate(date: Date, pattern: string, timezone: string | null) {
  const timeZone = timezone ?? undefined
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23', timeZone }).formatToParts(date).map(part => [part.type, part.value]))
  const shortMonth = new Intl.DateTimeFormat('en-US', { month: 'short', timeZone }).format(date)
  const longMonth = new Intl.DateTimeFormat('en-US', { month: 'long', timeZone }).format(date)
  const tokens: Record<string, string> = { Y: parts.year, y: parts.year.slice(-2), m: parts.month, n: String(Number(parts.month)), d: parts.day, j: String(Number(parts.day)), M: shortMonth, F: longMonth, H: parts.hour, i: parts.minute, s: parts.second }
  return pattern.replace(/[YymndjMFHis]/g, token => tokens[token] ?? token)
}
async function copy() {
  try {
    await navigator.clipboard.writeText(props.component.copyableState ?? (props.component.type === 'code-entry' ? codeSource.value : props.component.contentType === 'html' ? richPlainText.value : props.component.plainContent ?? String(props.value ?? '')))
    copied.value = true
    copyStatus.value = props.component.copyMessage ?? 'Copied'
    if (props.component.copyMessageDuration !== 0) setTimeout(() => { copied.value = false; copyStatus.value = '' }, props.component.copyMessageDuration ?? 2000)
  } catch { copied.value = false; copyStatus.value = 'Unable to copy' }
}
function plainTextFromHtml(html: string) {
  if (typeof document === 'undefined') return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
  const element = document.createElement('div')
  element.innerHTML = html

  return (element.textContent ?? '').replace(/\s+/g, ' ').trim()
}
</script>

<template>
  <span v-if="component.type === 'icon-entry'" :aria-label="iconEntryStates.length > 1 ? component.label : undefined" :class="component.listWithLineBreaks ? 'grid gap-1' : 'inline-flex flex-wrap items-center gap-2'" data-slot="icon-list" :role="iconEntryStates.length > 1 ? 'group' : undefined"><template v-for="(state, index) in iconEntryStates" :key="`${iconEntryName(state)}-${index}`"><span v-if="iconEntryName(state)" :aria-label="iconEntryLabel(state)" :class="`inline-flex items-center ${iconEntrySize()} ${iconEntryTone(state)}`.trim()" data-slot="icon" role="img" :style="iconEntryTone(state) ? undefined : { color: iconEntryColor(state) ?? undefined }"><NamedIcon :fallback="iconEntryFallback(iconEntryName(state) as string)" :icons="icons" :name="iconEntryName(state) as string" :registries="registries" /></span></template></span>
  <ImageEntryValue v-else-if="component.type === 'image-entry'" :component="component" :empty-class="classNames.empty" :empty-value="emptyValue" :value="value" />
  <template v-else-if="component.type === 'color-entry'"><span v-if="empty" :class="classNames.empty" data-slot="empty-value">{{ component.placeholder ?? emptyValue }}</span><span v-else class="inline-flex items-center gap-2"><span :aria-label="`${component.label}: ${String(value)}`" class="size-5 rounded-sm ring-1 ring-(--inlay-infolist-border)" data-slot="color-preview" role="img" :style="{ backgroundColor: String(value) }" /><span>{{ String(value) }}</span><button v-if="component.copyable" :aria-label="`Copy ${component.label}`" class="rounded px-2 py-1 text-xs ring-1 ring-(--inlay-infolist-border)" type="button" @click="copy">Copy</button><span v-if="component.copyable" class="sr-only" aria-live="polite">{{ copyStatus }}</span></span></template>
  <template v-else-if="component.type === 'code-entry'">
    <div v-if="codeSource !== ''" class="min-w-0 overflow-hidden rounded-(--inlay-infolist-radius) bg-(--inlay-infolist-surface) text-(--inlay-infolist-text) ring-1 ring-(--inlay-infolist-border)" data-slot="code-entry">
      <div class="flex min-h-10 items-center justify-between gap-3 border-b border-(--inlay-infolist-border) px-3 py-1.5">
        <span class="truncate font-mono text-base/6 text-(--inlay-infolist-muted) sm:text-xs/5">{{ component.grammar ?? 'txt' }}</span>
        <button v-if="component.copyable" :aria-label="`Copy ${component.label}`" class="ml-auto rounded-md px-2 py-1 text-xs font-medium ring-1 ring-(--inlay-infolist-border) hover:bg-(--inlay-infolist-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-infolist-accent)" data-slot="copy" type="button" @click="copy">{{ copyStatus || 'Copy' }}</button>
        <span v-if="component.copyable" class="sr-only" aria-live="polite">{{ copyStatus }}</span>
      </div>
      <div v-if="highlightedCode" class="min-w-0 overflow-x-auto [&_.phiki]:m-0 [&_.phiki]:min-w-max [&_.phiki]:bg-transparent! [&_.phiki]:p-4 [&_.phiki]:font-mono [&_.phiki]:text-base/7 sm:[&_.phiki]:text-sm/6 dark:[&_.phiki]:text-[var(--phiki-dark-color)]! dark:[&_.phiki_.token]:text-[var(--phiki-dark-color)]!" data-highlighted="true" v-html="highlightedCode" />
      <pre v-else class="m-0 min-w-max overflow-x-auto bg-(--inlay-infolist-surface-muted) p-4 font-mono text-base/7 text-(--inlay-infolist-text) sm:text-sm/6"><code>{{ codeSource }}</code></pre>
    </div>
    <span v-else :class="classNames.empty" data-slot="empty-value">{{ component.placeholder ?? emptyValue }}</span>
  </template>
  <table v-else-if="component.type === 'key-value-entry'" class="w-full text-left text-sm" data-slot="key-value"><caption class="sr-only">{{ component.label }}</caption><thead><tr><th class="py-1 pr-3" scope="col">{{ component.keyLabel ?? 'Key' }}</th><th class="py-1" scope="col">{{ component.valueLabel ?? 'Value' }}</th></tr></thead><tbody><tr v-for="([key, item]) in keyValues" :key="key" class="border-t border-(--inlay-infolist-border)"><th class="py-1 pr-3 font-medium" scope="row">{{ key }}</th><td class="py-1">{{ keyValueText(item) }}</td></tr><tr v-if="keyValues.length === 0"><td :class="`py-1 ${classNames.empty ?? ''}`.trim()" colspan="2" data-slot="empty-value">{{ component.placeholder ?? emptyValue }}</td></tr></tbody></table>
  <div v-else :class="[component.wrap === false ? 'whitespace-nowrap' : '', component.prose || component.markdown ? 'prose max-w-none dark:prose-invert' : '']" :data-prose="component.prose || component.markdown ? 'true' : undefined" :data-wrap="component.wrap === false ? 'false' : 'true'">
    <div v-if="component.contentType === 'html'" class="min-w-0">
      <span v-if="component.icon && component.iconPosition !== 'after'" :class="iconColorClass" :style="iconColorStyle"><NamedIcon icon-class="mr-1.5 inline-block" :icons="icons" :name="component.icon" :registries="registries" /></span>
      <span>{{ component.prefix }}</span>
      <div class="min-w-0 text-base/7 break-words sm:text-sm/6 [&_a]:text-(--inlay-infolist-accent) [&_a]:underline [&_a]:underline-offset-2 [&_blockquote]:border-l-2 [&_blockquote]:border-(--inlay-infolist-border) [&_blockquote]:pl-4 [&_code]:font-mono [&_code]:text-[0.9em] [&_h1]:text-xl [&_h1]:font-semibold [&_h2]:text-lg [&_h2]:font-semibold [&_li+li]:mt-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_p+p]:mt-3 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-(--inlay-infolist-surface-muted) [&_pre]:p-3 [&_pre]:text-(--inlay-infolist-text) [&_strong]:font-semibold [&_table]:w-full [&_table]:text-left [&_td]:border [&_td]:border-(--inlay-infolist-border) [&_td]:p-2 [&_th]:border [&_th]:border-(--inlay-infolist-border) [&_th]:p-2 [&_ul]:list-disc [&_ul]:pl-5" :data-prose="component.prose || component.markdown ? 'true' : undefined" data-slot="rich-content" :style="clampStyle" v-html="richContent" />
      <span>{{ component.suffix }}</span>
      <span v-if="component.icon && component.iconPosition === 'after'" :class="iconColorClass" :style="iconColorStyle"><NamedIcon icon-class="ml-1.5 inline-block" :icons="icons" :name="component.icon" :registries="registries" /></span>
    </div>
    <div v-else-if="isList" class="min-w-0">
      <span v-if="component.icon && component.iconPosition !== 'after'" :class="`mr-1.5 inline-block ${iconColorClass}`" :style="iconColorStyle"><NamedIcon :icons="icons" :name="component.icon" :registries="registries" /></span>
      <span>{{ component.prefix }}</span>
      <component :is="href ? 'a' : 'div'" :class="href ? 'text-(--inlay-infolist-accent) underline underline-offset-2' : ''" :href="href ?? undefined" :rel="component.openUrlInNewTab ? 'noreferrer' : undefined" :target="component.openUrlInNewTab ? '_blank' : undefined">
        <ul :class="`${component.bulleted ? 'list-disc pl-5' : ''} grid gap-1`" :role="component.bulleted ? undefined : 'list'"><li v-for="(item, index) in visibleListItems" :key="`${item}:${index}`">{{ item }}</li></ul>
      </component>
      <button v-if="remainingListItems > 0 && component.expandableLimitedList" :aria-expanded="listExpanded" class="mt-1.5 rounded-md px-1.5 py-1 text-sm font-medium text-(--inlay-infolist-accent) hover:bg-(--inlay-infolist-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-infolist-accent)" data-slot="list-toggle" type="button" @click="listExpanded = !listExpanded">{{ listExpanded ? 'Show less' : `Show ${remainingListItems} more` }}</button>
      <span>{{ component.suffix }}</span>
      <span v-if="component.icon && component.iconPosition === 'after'" :class="`ml-1.5 inline-block ${iconColorClass}`" :style="iconColorStyle"><NamedIcon :icons="icons" :name="component.icon" :registries="registries" /></span>
    </div>
    <template v-else><span v-if="component.icon && component.iconPosition !== 'after'" :class="`mr-1.5 inline-block ${iconColorClass}`" :style="iconColorStyle"><NamedIcon :icons="icons" :name="component.icon" :registries="registries" /></span><a v-if="href && !empty" :class="`text-(--inlay-infolist-accent) underline underline-offset-2 ${component.badge ? 'inline-flex rounded-full bg-(--inlay-infolist-info-surface) px-2 py-0.5 no-underline' : ''}`" data-slot="link" :href="href" :rel="component.openUrlInNewTab ? 'noreferrer' : undefined" :style="clampStyle" :target="component.openUrlInNewTab ? '_blank' : undefined">{{ text }}</a><span v-else :class="component.badge ? 'inline-flex rounded-full bg-(--inlay-infolist-surface-muted) px-2 py-0.5 text-sm' : ''" :data-slot="empty ? 'empty-value' : undefined" :style="clampStyle">{{ text }}</span><span v-if="component.icon && component.iconPosition === 'after'" :class="`ml-1.5 inline-block ${iconColorClass}`" :style="iconColorStyle"><NamedIcon :icons="icons" :name="component.icon" :registries="registries" /></span></template>
    <button v-if="component.copyable && !empty" :aria-label="`Copy ${component.label}`" class="ml-2 rounded px-2 py-1 text-xs ring-1 ring-(--inlay-infolist-border)" data-slot="copy" type="button" @click="copy">Copy</button><span v-if="component.copyable" class="sr-only" aria-live="polite">{{ copyStatus }}</span>
  </div>
</template>
