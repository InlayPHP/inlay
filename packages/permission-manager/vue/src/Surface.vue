<script setup lang="ts">
withDefaults(defineProps<{ className?: string }>(), { className: '' })
</script>

<template>
  <section :class="`mt-7 min-w-0 rounded-(--inlay-radius) bg-(--inlay-surface) p-5 shadow-(--inlay-shadow) ring-1 ring-(--inlay-border) sm:p-6 ${className}`.trim()">
    <slot />
  </section>
</template>
