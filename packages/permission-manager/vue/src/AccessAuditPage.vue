<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Table } from '@inlayphp/tables-vue'
import PageShell from './PageShell.vue'
import Surface from './Surface.vue'
import { auditAbilitiesTable, auditStaleTable, tableTheme } from './pages'
import type { AccessAuditPageProps } from './types'

/**
 * Compares abilities declared in PHP with the permissions actually stored.
 *
 * Both tables are built from the audit payload by the shared helpers, so this
 * screen shows the same rows the React one does rather than shaping its own.
 */
const props = withDefaults(defineProps<AccessAuditPageProps>(), { classNames: () => ({}), className: '' })

const summary = computed<Array<[string, number]>>(() => [
  ['Registered', props.audit.summary.registered],
  ['Synced', props.audit.summary.synced],
  ['Missing', props.audit.summary.missing],
  ['Stale', props.audit.summary.stale],
])

const abilitiesTable = computed(() => auditAbilitiesTable(props.audit))
const staleTable = computed(() => auditStaleTable(props.audit))
</script>

<template>
  <Head title="Access audit" />
  <PageShell
    :class-name="className"
    :class-names="classNames"
    :description="`Compare abilities declared in PHP with permissions stored for the ${audit.guard} guard and see which roles grant them.`"
    eyebrow="Access control"
    :theme="theme"
    title="Access audit"
  >
    <dl :class="`mt-7 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 ${classNames.auditSummary ?? ''}`.trim()">
      <div v-for="[label, value] in summary" :key="label" class="rounded-(--inlay-radius) bg-(--inlay-surface) p-4 shadow-(--inlay-shadow) ring-1 ring-(--inlay-border)">
        <dt class="text-sm text-(--inlay-muted)">{{ label }}</dt>
        <dd class="mt-1 text-2xl font-semibold">{{ value }}</dd>
      </div>
    </dl>
    <Surface :class-name="classNames.surface">
      <Table :class-name="`${classNames.table ?? ''} ${classNames.auditTable ?? ''}`.trim()" :class-names="tableClassNames" :resource="abilitiesTable" :theme="tableTheme(theme)" />
    </Surface>
    <Surface v-if="audit.stale.length" :class-name="classNames.surface">
      <h2 class="text-lg font-semibold">Stale permissions</h2>
      <p class="mt-1 text-sm text-(--inlay-muted)">Stored permissions that are not declared by the current application.</p>
      <Table :class-name="`mt-4 ${classNames.table ?? ''} ${classNames.auditTable ?? ''}`.trim()" :class-names="tableClassNames" :resource="staleTable" :theme="tableTheme(theme)" />
    </Surface>
  </PageShell>
</template>
