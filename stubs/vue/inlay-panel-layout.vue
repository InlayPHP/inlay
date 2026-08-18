<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Panel } from '@inlayphp/panels-vue';
import type { PanelResource } from '@inlayphp/panels-vue';

type PanelPageProps = {
    auth?: { user?: { name?: string; email?: string } | null };
    inlayPanel: PanelResource;
    inlayPage?: Record<string, unknown>;
    page?: Record<string, unknown>;
    resource?: Record<string, unknown>;
};

const page = usePage<PanelPageProps>();

function logout(): void {
    router.post(`${page.props.inlayPanel.path}/logout`);
}
</script>

<template>
    <Panel
        :condition-values="{ page: page.props.inlayPage ?? page.props.page, resource: page.props.resource }"
        :resource="page.props.inlayPanel"
        :on-navigate="(href) => router.visit(href)"
    >
        <template #header-start>
            <nav aria-label="Breadcrumb" class="hidden items-center gap-2 text-xs text-(--inlay-muted) lg:flex" data-slot="topbar-breadcrumb">
                <span>Workspace</span>
                <span aria-hidden="true">/</span>
                <strong class="font-semibold text-(--inlay-text)">Administration</strong>
            </nav>
        </template>
        <template #sidebar-footer="{ context }">
            <div v-if="context.collapsed" aria-label="Current workspace" class="grid size-10 place-items-center self-center rounded-(--inlay-radius) border border-(--inlay-panel-sidebar-border) bg-(--inlay-panel-sidebar-hover) text-sm font-semibold text-(--inlay-panel-sidebar-active-foreground)" data-slot="workspace-card">I</div>
            <div v-else class="rounded-(--inlay-radius) border border-(--inlay-panel-sidebar-border) bg-(--inlay-panel-sidebar-hover) p-3" data-slot="workspace-card">
                <div class="mb-2 flex items-center justify-between"><span class="text-[11px] text-(--inlay-panel-sidebar-muted)">Current workspace</span><span aria-label="Connected" class="size-2 rounded-full bg-(--inlay-panel-success) shadow-[0_0_0_3px_var(--inlay-panel-success-surface)]" /></div>
                <p class="text-sm font-semibold text-(--inlay-panel-sidebar-text)">{{ page.props.inlayPanel.brandName ?? 'Inlay' }}</p>
                <p class="text-[11px] text-(--inlay-panel-sidebar-muted)">Production</p>
            </div>
        </template>
        <template #header-end>
            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block">
                    <p class="text-sm font-medium">
                        {{ page.props.auth?.user?.name ?? 'Account' }}
                    </p>
                    <p class="text-xs text-(--inlay-muted)">
                        {{ page.props.auth?.user?.email ?? '' }}
                    </p>
                </div>
                <button
                    class="inline-flex min-h-(--inlay-button-height) items-center rounded-(--inlay-radius) border border-(--inlay-control-border) px-3 text-sm font-medium transition hover:bg-(--inlay-hover)"
                    type="button"
                    @click="logout"
                >
                    Sign out
                </button>
            </div>
        </template>

        <slot />
    </Panel>
</template>
