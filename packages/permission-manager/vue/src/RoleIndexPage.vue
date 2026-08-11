<script setup lang="ts">
import ListPage from './ListPage.vue'
import { tableDescription } from './pages'
import type { ListPageProps } from './types'

defineProps<ListPageProps>()
</script>

<template>
  <ListPage v-bind="$props" :description="tableDescription.roles" eyebrow="Access control" />
</template>
