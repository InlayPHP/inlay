<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { Table } from '@inlayphp/tables-vue'
import Flash from './Flash.vue'
import PageShell from './PageShell.vue'
import PrimaryLink from './PrimaryLink.vue'
import Surface from './Surface.vue'
import { tableTheme } from './pages'
import type { ListPageProps } from './types'

/** The shared index screen behind the roles and permissions lists. */
const props = withDefaults(defineProps<ListPageProps & { description: string; eyebrow: string }>(), {
  classNames: () => ({}),
  className: '',
})

const createUrl = props.resource.pages.create?.url
</script>

<template>
  <Head :title="resource.pluralLabel" />
  <PageShell
    :class-name="className"
    :class-names="classNames"
    :description="description"
    :eyebrow="eyebrow"
    :theme="theme"
    :title="resource.pluralLabel"
  >
    <template v-if="createUrl" #action>
      <PrimaryLink :href="createUrl">Create {{ resource.label.toLocaleLowerCase() }}</PrimaryLink>
    </template>
    <Flash :class-name="classNames.flash" :message="flash?.success" />
    <Surface :class-name="classNames.surface">
      <Table :class-name="classNames.table" :class-names="tableClassNames" :resource="table" :theme="tableTheme(theme)" />
    </Surface>
  </PageShell>
</template>
