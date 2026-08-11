<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Form } from '@inlayphp/forms-vue';
import type { FormErrors, FormResource } from '@inlayphp/forms-vue';
import { Panel } from '@inlayphp/panels-vue';
import type { PanelDirectoryEntry, PanelResource } from '@inlayphp/panels-vue';
import { RelationManagers } from '@inlayphp/resources-vue';
import type {
    RelationManagerResource,
    ResourceBreadcrumb,
    ResourceSubNavigationItem,
} from '@inlayphp/resources-vue';
import { ResourcePage } from '@inlayphp/resources-vue';
import AdminPanelHeader from '@/vue/components/admin-panel-header.vue';

type PageProps = {
    inlayPanel: PanelResource;
    inlayPanels: PanelDirectoryEntry[];
    resource: Record<string, unknown>;
    form: FormResource;
    record?: { id: number | string; name?: string };
    relations?: RelationManagerResource[];
    heading: string;
    subheading?: string | null;
    breadcrumbs?: ResourceBreadcrumb[];
    subNavigation?: ResourceSubNavigationItem[];
    errors?: FormErrors;
};

const page = usePage<PageProps>();
defineProps<PageProps>();
</script>

<template>
    <Head :title="heading" />
    <Panel
        :condition-values="{ resource, record }"
        :resource="inlayPanel"
        :on-navigate="(href) => router.visit(href)"
    >
        <template #header-end>
            <AdminPanelHeader
                :current-panel-id="inlayPanel.id"
                :panels="inlayPanels"
            />
        </template>

        <ResourcePage
            :breadcrumbs="breadcrumbs ?? []"
            :heading="heading"
            :subheading="subheading"
            :sub-navigation="subNavigation ?? []"
        >
            <template #actions>
                <Link
                    class="inline-flex min-h-10 items-center rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) px-4 py-2 text-sm font-semibold text-(--inlay-text) transition-colors hover:bg-(--inlay-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)"
                    href="/vue/resources/users"
                >
                    Back to users
                </Link>
            </template>

            <section
                class="min-w-0 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-5 shadow-sm"
                data-slot="vue-resource-form"
            >
                <Form :errors="page.props.errors ?? {}" :resource="form" />
            </section>

            <section
                v-if="record && relations?.length"
                class="mt-8 min-w-0 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-5 shadow-sm"
                data-slot="vue-resource-relations"
            >
                <RelationManagers
                    :resources="relations"
                    @changed="router.reload({ only: ['relations'] })"
                />
            </section>
        </ResourcePage>
    </Panel>
</template>
