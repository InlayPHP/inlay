<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Table } from '@inlayphp/tables-vue';
import type { TableResource } from '@inlayphp/tables-vue';
import {
    standaloneThemeClass,
    standaloneThemeStyle,
} from '@/vue/components/standalone-theme';

/**
 * The same payload `standalone/table` serves to React, rendered by the Vue table.
 *
 * No icon registry is passed on purpose: an unresolved icon is what surfaced the
 * worst renderer bug this project has had, where the icon's name was printed as
 * text, so the default path is the one worth exercising against a real server.
 */
defineProps<{ table: TableResource }>();
</script>

<template>
    <Head title="Standalone table (Vue)" />
    <main
        :class="['mx-auto max-w-6xl p-6', standaloneThemeClass]"
        data-slot="vue-playground"
        :style="standaloneThemeStyle"
    >
        <h1 class="mb-6 text-2xl font-semibold">
            Standalone table, rendered by Vue
        </h1>
        <Table :resource="table" />
    </main>
</template>
