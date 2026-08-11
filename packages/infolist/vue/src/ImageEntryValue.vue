<script setup lang="ts">
import { computed } from 'vue'
import type { CSSProperties } from 'vue'
import type { InfolistComponent } from './types'
import { safeUrl } from './url'

const props = withDefaults(defineProps<{ component: InfolistComponent; value: unknown; emptyValue?: string; emptyClass?: string }>(), {
  emptyValue: '—',
  emptyClass: '',
})

const imageUrls = computed(() => {
  const raw = typeof props.component.url === 'string' ? [props.component.url] : Array.isArray(props.value) ? props.value : [props.value]
  const images = raw.map(item => typeof item === 'string' ? safeUrl(item) : undefined).filter((url): url is string => Boolean(url))
  const fallback = safeUrl(props.component.defaultImageUrl)
  if (images.length === 0 && fallback) images.push(fallback)

  return images
})
const visibleImages = computed(() => props.component.limit ? imageUrls.value.slice(0, props.component.limit) : imageUrls.value)
const remainingImages = computed(() => imageUrls.value.length - visibleImages.value.length)
const imageAttributeParts = computed(() => {
  const source = props.component.extraImgAttributes ?? {}
  const className = typeof source.className === 'string' ? source.className : typeof source.class === 'string' ? source.class : ''
  const alt = typeof source.alt === 'string' ? source.alt : null
  const loading: 'lazy' | 'eager' = source.loading === 'eager' ? 'eager' : 'lazy'
  const unsafe = new Set(['alt', 'children', 'class', 'className', 'dangerouslySetInnerHTML', 'height', 'innerHTML', 'key', 'loading', 'ref', 'src', 'srcDoc', 'srcSet', 'srcdoc', 'srcset', 'style', 'textContent', 'width'])
  const attributes = Object.fromEntries(Object.entries(source).filter(([key]) => !unsafe.has(key) && !key.toLowerCase().startsWith('on')))

  return { alt, attributes, className, loading }
})
const imageClass = computed(() => `${props.component.circular ? 'rounded-full' : props.component.square ? 'rounded-none' : 'rounded-(--inlay-infolist-radius)'} object-cover outline-1 -outline-offset-1 outline-(--inlay-infolist-border) ${imageAttributeParts.value.className}`.trim())
const remainingTextClass = computed(() => props.component.limitedRemainingTextSize === 'extra-small'
  ? 'text-sm/5 sm:text-xs/4'
  : props.component.limitedRemainingTextSize === 'medium'
    ? 'text-lg/6 sm:text-base/6'
    : props.component.limitedRemainingTextSize === 'large'
      ? 'text-xl/7 sm:text-lg/6'
      : 'text-base/6 sm:text-sm/5')

function imageAlt(index: number) {
  if (imageAttributeParts.value.alt !== null) return imageAttributeParts.value.alt
  if (Array.isArray(props.component.alt)) return props.component.alt[index] ?? ''
  if (props.component.alt === null || props.component.alt === undefined) return ''

  return imageUrls.value.length > 1 ? `${props.component.alt} ${index + 1}` : props.component.alt
}

function imageStyle(index: number): CSSProperties | undefined {
  if (!props.component.stacked) return undefined

  return {
    boxShadow: (props.component.ring ?? 3) > 0 ? `0 0 0 ${props.component.ring ?? 3}px var(--inlay-infolist-surface)` : undefined,
    marginInlineStart: index > 0 ? `${-(props.component.overlap ?? 4) * 2}px` : undefined,
    zIndex: visibleImages.value.length - index,
  }
}
function imageDimensionAttribute(value: string | number | null | undefined): number | undefined { return typeof value === 'number' ? value : undefined }
function imageDimensionStyle(value: string | number | null | undefined, fallback?: string | number | null): CSSProperties {
  return {
    width: typeof value === 'number' ? `${value}px` : value ?? undefined,
    ...(fallback === undefined ? {} : { height: typeof fallback === 'number' ? `${fallback}px` : fallback ?? undefined }),
  }
}
</script>

<template>
  <span v-if="imageUrls.length === 0" :class="emptyClass" data-slot="empty-value">{{ component.placeholder ?? emptyValue }}</span>
  <div v-else :aria-label="imageUrls.length > 1 ? component.label : undefined" :class="`flex max-w-full items-center ${component.stacked ? 'isolate' : 'flex-wrap gap-2'}`" data-slot="image-group" :role="imageUrls.length > 1 ? 'group' : undefined">
    <img v-for="(source, index) in visibleImages" :key="`${source}-${index}`" v-bind="imageAttributeParts.attributes" :alt="imageAlt(index)" :class="imageClass" data-slot="image" :height="imageDimensionAttribute(component.height ?? 40)" :loading="imageAttributeParts.loading" :src="source" :style="{ ...imageDimensionStyle(component.square ? component.height ?? 40 : component.width ?? 40, component.height ?? 40), ...(imageStyle(index) ?? {}) }" :width="imageDimensionAttribute(component.square ? component.height ?? 40 : component.width ?? 40)">
    <span v-if="remainingImages > 0 && component.limitedRemainingText" :aria-label="`${remainingImages} more images`" :class="`${remainingTextClass} font-medium text-(--inlay-infolist-muted) ${component.limitedRemainingTextSeparate ? 'inline-flex items-center justify-center' : ''}`.trim()" data-slot="image-remaining" :style="component.limitedRemainingTextSeparate ? imageDimensionStyle(component.square ? component.height ?? 40 : component.width ?? 40, component.height ?? 40) : undefined">+{{ remainingImages }}</span>
  </div>
</template>
