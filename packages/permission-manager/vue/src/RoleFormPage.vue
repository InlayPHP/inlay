<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed, defineComponent, h } from 'vue'
import { Form } from '@inlayphp/forms-vue'
import BackLink from './BackLink.vue'
import PageShell from './PageShell.vue'
import PermissionMatrix from './PermissionMatrix.vue'
import Surface from './Surface.vue'
import { formTheme } from './pages'
import type { FormPageProps } from './types'

/**
 * The role editor. Its permission field is rendered by the matrix rather than a
 * plain checkbox list, which is a renderer choice — the options still come from
 * PHP.
 */
const props = withDefaults(defineProps<FormPageProps>(), { errors: () => ({}), classNames: () => ({}), className: '' })

const title = computed(() => `${props.record ? 'Edit' : 'Create'} ${props.resource.label.toLocaleLowerCase()}`)

// The registry hands a renderer the field's own props; this forwards them and
// adds the ability metadata the page received, which a globally registered
// component could not know.
const matrixRenderer = defineComponent({
  inheritAttrs: false,
  setup: (_, { attrs }) => () => h(PermissionMatrix as never, { ...attrs, abilities: props.abilities ?? [] }),
})
</script>

<template>
  <Head :title="title" />
  <PageShell
    :class-name="className"
    :class-names="classNames"
    description="Give the role a clear name, choose its guard, and grant only the capabilities it needs."
    eyebrow="Access control"
    :theme="theme"
    :title="title"
  >
    <Surface :class-name="classNames.surface">
      <BackLink :href="resource.pages.index?.url ?? `/${resource.slug}`">{{ resource.pluralLabel }}</BackLink>
      <div class="mt-7">
        <Form :class-name="classNames.form" :errors="errors" :renderers="{ 'checkbox-list': matrixRenderer }" :resource="form" :theme="formTheme(theme)" />
      </div>
    </Surface>
  </PageShell>
</template>
