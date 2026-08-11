<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Form } from '@inlayphp/forms-vue'
import BackLink from './BackLink.vue'
import PageShell from './PageShell.vue'
import Surface from './Surface.vue'
import { formTheme } from './pages'
import type { UserAccessFormPageProps } from './types'

const props = withDefaults(defineProps<UserAccessFormPageProps>(), { errors: () => ({}), classNames: () => ({}), className: '' })

const title = computed(() => `Roles for ${props.record.name}`)
const description = computed(() => props.record.email
  ? `Manage role membership for ${props.record.email}.`
  : 'Manage this user’s role membership.')
</script>

<template>
  <Head :title="title" />
  <PageShell
    :class-name="className"
    :class-names="classNames"
    :description="description"
    eyebrow="Access control"
    max-width="narrow"
    :theme="theme"
    :title="title"
  >
    <Surface :class-name="classNames.surface">
      <BackLink :href="userAccess.baseUrl">{{ userAccess.label }}</BackLink>
      <div class="mt-7"><Form :class-name="classNames.form" :errors="errors" :resource="form" :theme="formTheme(theme)" /></div>
    </Surface>
  </PageShell>
</template>
