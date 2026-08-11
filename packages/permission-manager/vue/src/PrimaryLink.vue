<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { buttonPrimaryClass } from '@inlayphp/ui-vue'

defineProps<{ href: string }>()
</script>

<template>
  <Link
    :class="`${buttonPrimaryClass} min-h-(--inlay-button-lg-height) px-4 py-2`"
    :href="href"
  ><slot /></Link>
</template>
