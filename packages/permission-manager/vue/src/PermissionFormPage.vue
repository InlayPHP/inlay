<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Form } from '@inlayphp/forms-vue'
import BackLink from './BackLink.vue'
import PageShell from './PageShell.vue'
import Surface from './Surface.vue'
import { formTheme } from './pages'
import type { FormPageProps } from './types'

const props = withDefaults(defineProps<FormPageProps>(), { errors: () => ({}), classNames: () => ({}), className: '' })

const title = computed(() => `${props.record ? 'Edit' : 'Create'} ${props.resource.label.toLocaleLowerCase()}`)
</script>

<template>
  <Head :title="title" />
  <PageShell
    :class-name="className"
    :class-names="classNames"
    description="Permissions are atomic abilities. Prefer assigning them through roles for day-to-day administration."
    eyebrow="Access control"
    :theme="theme"
    :title="title"
  >
    <Surface :class-name="classNames.surface">
      <BackLink :href="resource.pages.index?.url ?? `/${resource.slug}`">{{ resource.pluralLabel }}</BackLink>
      <div class="mt-7"><Form :class-name="classNames.form" :errors="errors" :resource="form" :theme="formTheme(theme)" /></div>
    </Surface>
  </PageShell>
</template>
