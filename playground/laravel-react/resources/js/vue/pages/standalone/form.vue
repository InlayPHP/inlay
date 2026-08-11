<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Form } from '@inlayphp/forms-vue';
import type { FormResource } from '@inlayphp/forms-vue';
import {
    standaloneThemeClass,
    standaloneThemeStyle,
} from '@/vue/components/standalone-theme';

/**
 * The same payload `standalone/form` serves to React, rendered by the Vue form.
 *
 * Submitting it empty is the case that matters: validation errors are what read as
 * black instead of red for as long as the danger token referenced itself, and only a
 * browser computes that.
 */
defineProps<{ form: FormResource; errors?: Record<string, string> }>();
</script>

<template>
    <Head title="Standalone form (Vue)" />
    <main
        :class="['mx-auto max-w-3xl p-6', standaloneThemeClass]"
        data-slot="vue-playground"
        :style="standaloneThemeStyle"
    >
        <h1 class="mb-6 text-2xl font-semibold">
            Standalone form, rendered by Vue
        </h1>
        <Form :errors="errors ?? {}" :resource="form" />
    </main>
</template>
