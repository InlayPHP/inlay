<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Panel } from '@inlayphp/panels-vue';
import type { PanelDirectoryEntry, PanelResource } from '@inlayphp/panels-vue';
import { WidgetDashboard } from '@inlayphp/widgets-vue';
import type { WidgetDashboardResource } from '@inlayphp/widgets-vue';
import AdminPanelHeader from '@/vue/components/admin-panel-header.vue';

defineProps<{
    inlayPanel: PanelResource;
    inlayPanels: PanelDirectoryEntry[];
    inlayPage?: Record<string, unknown>;
    inlayWidgets: WidgetDashboardResource;
}>();
</script>

<template>
    <Panel
        :condition-values="{ page: inlayPage }"
        :resource="inlayPanel"
        :on-navigate="(href) => router.visit(href)"
    >
        <template #header-end>
            <AdminPanelHeader
                :current-panel-id="inlayPanel.id"
                :panels="inlayPanels"
            />
        </template>

        <div class="mx-auto max-w-[90rem] space-y-7">
            <header>
                <p class="text-sm font-semibold text-(--inlay-accent)">
                    Vue panel showcase
                </p>
                <h1 class="mt-1 text-3xl font-semibold tracking-tight">
                    Dashboard
                </h1>
                <p class="mt-2 max-w-2xl text-sm text-(--inlay-muted)">
                    The same Laravel panel and widget contracts rendered by Vue
                    3.
                </p>
            </header>

            <WidgetDashboard :resource="inlayWidgets" />
        </div>
    </Panel>
</template>
