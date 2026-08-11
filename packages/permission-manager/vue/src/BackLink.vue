<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { buttonBaseClass } from '@inlayphp/ui-vue'

defineProps<{ href: string }>()
</script>

<template>
  <nav aria-label="Breadcrumb">
    <ol>
      <li>
        <Link
          :class="`${buttonBaseClass} min-h-(--inlay-button-sm-height) gap-1.5 border-transparent px-2.5 font-medium text-(--inlay-muted) shadow-none hover:bg-(--inlay-surface-muted) hover:text-(--inlay-foreground)`"
          :href="href"
        >
          <svg aria-hidden="true" class="size-4" fill="none" viewBox="0 0 16 16"><path d="m10 3-5 5 5 5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" /></svg>
          <span><slot /></span>
        </Link>
      </li>
    </ol>
  </nav>
</template>
