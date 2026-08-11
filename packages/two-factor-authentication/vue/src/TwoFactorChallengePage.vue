<script setup lang="ts">
import { Form } from '@inlayphp/forms-vue'
import type { FormErrors, FormResource, FormTheme } from '@inlayphp/forms-vue'

withDefaults(defineProps<{ challengeForm: FormResource; errors?: FormErrors; theme?: FormTheme; className?: string }>(), { errors: () => ({}), theme: () => ({}), className: '' })
</script>

<template>
  <main :class="`mx-auto w-full max-w-lg text-(--inlay-foreground) antialiased ${className}`.trim()" data-slot="two-factor-challenge">
    <header>
      <p class="text-sm font-semibold tracking-wide text-(--inlay-accent) uppercase">Security</p>
      <h1 class="mt-2 text-3xl font-semibold tracking-tight">Verify your sign-in</h1>
      <p class="mt-3 text-sm leading-6 text-(--inlay-muted)">Enter the code from your authenticator app, or use one of your recovery codes.</p>
    </header>
    <div class="mt-7 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-6 shadow-sm">
      <Form :errors="errors" :resource="challengeForm" :theme="theme" />
    </div>
  </main>
</template>
