<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { PanelResource } from '@inlayphp/panels-vue';
import { WidgetDashboard } from '@inlayphp/widgets-vue';
import type { WidgetDashboardResource } from '@inlayphp/widgets-vue';
import InlayPanelLayout from '../../layouts/inlay-panel-layout.vue';

defineProps<{
    inlayPanel: PanelResource;
    inlayWidgets?: WidgetDashboardResource;
}>();

const destinations = [
    ['Users', 'Create and manage panel accounts.', '/{{ panel }}/users'],
    ['Account settings', 'Update your profile and password.', '/{{ panel }}/settings/account'],
] as const;
</script>

<template>
    <InlayPanelLayout>
        <Head title="Dashboard" />
        <div class="mx-auto max-w-6xl space-y-6">
            <div>
                <p class="font-medium text-(--inlay-accent)">Administration</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight">Dashboard</h1>
                <p class="mt-2 text-sm text-(--inlay-muted)">Manage the application from one PHP-configured panel.</p>
            </div>
            <WidgetDashboard v-if="inlayWidgets" :resource="inlayWidgets" />
            <section class="grid gap-4 md:grid-cols-3">
                <Link
                    v-for="[title, description, href] in destinations"
                    :key="title"
                    class="rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-5 transition hover:bg-(--inlay-hover)"
                    :href="href"
                >
                    <h2 class="font-semibold">{{ title }}</h2>
                    <p class="mt-2 text-sm leading-6 text-(--inlay-muted)">{{ description }}</p>
                </Link>
            </section>
        </div>
    </InlayPanelLayout>
</template>
