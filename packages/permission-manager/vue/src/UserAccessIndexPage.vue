<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { Table } from '@inlayphp/tables-vue'
import Flash from './Flash.vue'
import PageShell from './PageShell.vue'
import Surface from './Surface.vue'
import { tableTheme } from './pages'
import type { UserAccessListPageProps } from './types'

withDefaults(defineProps<UserAccessListPageProps>(), { classNames: () => ({}), className: '' })
</script>

<template>
  <Head :title="userAccess.label" />
  <PageShell
    :class-name="className"
    :class-names="classNames"
    description="Assign reusable roles to users without duplicating policy rules."
    eyebrow="Access control"
    :theme="theme"
    :title="userAccess.label"
  >
    <Flash :class-name="classNames.flash" :message="flash?.success" />
    <Surface :class-name="classNames.surface">
      <Table :class-name="classNames.table" :class-names="tableClassNames" :resource="table" :theme="tableTheme(theme)" />
    </Surface>
  </PageShell>
</template>
