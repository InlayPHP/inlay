<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import MediaManager from './MediaManager.vue'
import type { MediaManagerPageProps } from './types'

defineProps<MediaManagerPageProps>()
</script>

<template>
  <Head title="Media library" /><main class="mx-auto w-full max-w-[100rem]"><div v-if="flash?.success" class="mb-5 rounded-(--inlay-radius) border border-(--inlay-success)/25 bg-(--inlay-success-surface) px-4 py-3 text-sm font-medium text-(--inlay-success)" role="status">{{ flash.success }}</div><MediaManager :resource="media" :theme="theme" /></main>
</template>
