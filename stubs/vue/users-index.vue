<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ResourcePage } from '@inlayphp/resources-vue';
import type { ResourceBreadcrumb, ResourceSubNavigationItem, ResourceTabsResource } from '@inlayphp/resources-vue';
import { Table } from '@inlayphp/tables-vue';
import type { TableResource } from '@inlayphp/tables-vue';
import InlayPanelLayout from '../../layouts/inlay-panel-layout.vue';

defineProps<{
    breadcrumbs?: ResourceBreadcrumb[];
    heading: string;
    subheading?: string | null;
    subNavigation?: ResourceSubNavigationItem[];
    table: TableResource;
    tabs?: ResourceTabsResource | null;
}>();
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
                <Table :resource="table" />
            </section>
        </ResourcePage>
    </InlayPanelLayout>
</template>
