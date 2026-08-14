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
