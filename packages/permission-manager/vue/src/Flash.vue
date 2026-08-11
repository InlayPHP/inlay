<script setup lang="ts">
/** A success notice, rendered only when the server sent one. */
withDefaults(defineProps<{ message?: string | null; className?: string }>(), { message: null, className: '' })
</script>

<template>
  <div
    v-if="message"
    :class="`mt-6 rounded-(--inlay-radius) border border-(--inlay-success)/25 bg-(--inlay-success-surface) px-4 py-3 text-sm font-medium text-(--inlay-success) ${className}`.trim()"
    role="status"
  >{{ message }}</div>
</template>
