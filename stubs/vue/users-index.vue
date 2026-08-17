<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PanelResource } from '@inlayphp/panels-vue';
import { ResourcePage } from '@inlayphp/resources-vue';
import type { ResourceBreadcrumb, ResourceSubNavigationItem, ResourceTabsResource } from '@inlayphp/resources-vue';
import { Table } from '@inlayphp/tables-vue';
import type { TableResource } from '@inlayphp/tables-vue';
import InlayPanelLayout from '../../layouts/inlay-panel-layout.vue';

type PageProps = {
    inlayPanel: PanelResource;
    breadcrumbs?: ResourceBreadcrumb[];
    heading: string;
    subheading?: string | null;
    subNavigation?: ResourceSubNavigationItem[];
    table: TableResource;
    tabs?: ResourceTabsResource | null;
};

defineProps<PageProps>();
const page = usePage<PageProps>();
const theme = computed(() => ({
    contract: 'inlay.themes.v1' as const,
    name: page.props.inlayPanel.themeName ?? page.props.inlayPanel.id,
    tokens: page.props.inlayPanel.theme,
    darkTokens: page.props.inlayPanel.darkTheme ?? {},
}));
</script>

<template>
    <InlayPanelLayout>
        <Head :title="heading" />
        <ResourcePage
            :breadcrumbs="breadcrumbs ?? []"
            :heading="heading"
            :sub-navigation="subNavigation ?? []"
            :subheading="subheading ?? 'Create and maintain panel accounts.'"
            :tabs="tabs ?? null"
        >
            <template #actions>
                <Link
                    class="inline-flex min-h-(--inlay-button-height) items-center rounded-(--inlay-radius) bg-(--inlay-accent) px-4 text-sm font-semibold text-(--inlay-accent-foreground) transition hover:opacity-90"
                    href="/{{ panel }}/users/create"
                >
                    Create user
                </Link>
            </template>
            <section class="rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-4 sm:p-6">
                <Table :resource="table" :theme="theme" />
            </section>
        </ResourcePage>
    </InlayPanelLayout>
</template>
