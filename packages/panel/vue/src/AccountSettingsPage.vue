<script setup lang="ts">
import { Form } from '@inlayphp/forms-vue'
import type { FormErrors, FormResource, FormTheme } from '@inlayphp/forms-vue'

/**
 * The visitor's own profile and password, as two PHP-defined forms.
 *
 * Both forms come from the server, so this page never decides which fields a
 * visitor may change — it only lays them out.
 */
withDefaults(defineProps<{
  profileForm: FormResource
  passwordForm: FormResource
  errors?: FormErrors
  flash?: { success?: string | null }
  theme?: FormTheme
  className?: string
}>(), { errors: () => ({}), flash: () => ({}), theme: () => ({}), className: '' })
</script>

<template>
  <main :class="`mx-auto w-full max-w-4xl text-(--inlay-foreground) antialiased ${className}`.trim()" data-slot="account-settings">
    <header>
      <p class="text-sm font-semibold tracking-wide text-(--inlay-accent) uppercase">Account</p>
      <h1 class="mt-2 text-3xl font-semibold tracking-tight">Account settings</h1>
      <p class="mt-3 max-w-2xl text-sm leading-6 text-(--inlay-muted)">Manage your own profile and password for this panel.</p>
    </header>
    <div
      v-if="flash.success"
      class="mt-6 rounded-(--inlay-radius) border border-(--inlay-success)/25 bg-(--inlay-success-surface) px-4 py-3 text-sm text-(--inlay-success)"
      role="status"
    >{{ flash.success }}</div>
    <div class="mt-7 grid gap-6">
      <Form :errors="errors" :resource="profileForm" :theme="theme" />
      <Form :errors="errors" :resource="passwordForm" :theme="theme" />
    </div>
  </main>
</template>
