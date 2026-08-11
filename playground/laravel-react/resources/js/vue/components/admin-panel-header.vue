<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PanelSwitcher } from '@inlayphp/panels-vue';
import type { PanelDirectoryEntry, PanelResource } from '@inlayphp/panels-vue';

defineProps<{
    currentPanelId: PanelResource['id'];
    panels: PanelDirectoryEntry[];
}>();
</script>

<template>
    <div class="flex items-center gap-1.5">
        <PanelSwitcher
            :current-panel-id="currentPanelId"
            :panels="panels"
            :on-navigate="(href) => router.visit(href)"
        />
        <button
            aria-label="Sign out"
            class="inline-flex min-h-10 min-w-10 items-center justify-center rounded-(--inlay-panel-radius) border border-(--inlay-panel-border) bg-(--inlay-panel-surface) px-2.5 text-sm text-(--inlay-panel-text) shadow-xs transition-colors hover:bg-(--inlay-panel-hover) focus-visible:ring-2 focus-visible:ring-(--inlay-panel-accent) focus-visible:outline-none"
            type="button"
            @click="router.post('/admin/logout')"
        >
            <svg
                aria-hidden="true"
                class="size-4"
                fill="none"
                viewBox="0 0 24 24"
            >
                <path
                    d="M9 5H5.75A1.75 1.75 0 0 0 4 6.75v10.5A1.75 1.75 0 0 0 5.75 19H9m5-3 4-4-4-4m4 4H9"
                    stroke="currentColor"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.7"
                />
            </svg>
        </button>
    </div>
</template>
