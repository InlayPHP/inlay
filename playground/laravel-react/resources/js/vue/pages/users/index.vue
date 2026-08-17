<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Form } from '@inlayphp/forms-vue';
import type { FormErrors, FormResource } from '@inlayphp/forms-vue';
import { Panel } from '@inlayphp/panels-vue';
import type { PanelDirectoryEntry, PanelResource } from '@inlayphp/panels-vue';
import { ResourcePage } from '@inlayphp/resources-vue';
import type {
    ResourceBreadcrumb,
    ResourceSubNavigationItem,
    ResourceTabsResource,
} from '@inlayphp/resources-vue';
import { Table } from '@inlayphp/tables-vue';
import type { TableResource } from '@inlayphp/tables-vue';
import AdminPanelHeader from '@/vue/components/admin-panel-header.vue';

type PageProps = {
    inlayPanel: PanelResource;
    inlayPanels: PanelDirectoryEntry[];
    resource: Record<string, unknown>;
    form: FormResource;
    table: TableResource;
    heading: string;
    subheading?: string | null;
    breadcrumbs?: ResourceBreadcrumb[];
    subNavigation?: ResourceSubNavigationItem[];
    tabs?: ResourceTabsResource | null;
    errors?: FormErrors;
};

defineProps<PageProps>();
const page = usePage<PageProps>();
const theme = computed(() => ({
    contract: 'inlay.themes.v1' as const,
    name: page.props.inlayPanel.themeName ?? page.props.inlayPanel.id,
    tokens: page.props.inlayPanel.theme,
    darkTokens: page.props.inlayPanel.darkTheme ?? {},
}));

function visitTab(tab: string): void {
    router.get(
        window.location.pathname,
        { tab },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head title="Users (Vue)" />
    <Panel
        :condition-values="{ resource }"
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
            :tabs="tabs ?? null"
            @tab-select="visitTab"
        >
            <template #actions>
                <Link
                    class="inline-flex min-h-10 items-center rounded-(--inlay-radius) bg-(--inlay-accent) px-4 py-2 text-sm font-semibold text-(--inlay-accent-foreground) shadow-sm transition-colors hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)"
                    href="/vue/resources/users/create"
                >
                    Create user
                </Link>
            </template>

            <div
                class="grid min-w-0 gap-8 xl:grid-cols-[minmax(18rem,24rem)_minmax(0,1fr)]"
            >
                <section
                    class="min-w-0 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-5 shadow-sm"
                    data-slot="vue-resource-form"
                >
                    <header class="mb-5">
                        <h2 class="text-lg font-semibold text-(--inlay-text)">
                            Create a user
                        </h2>
                        <p class="mt-1 text-sm text-(--inlay-muted)">
                            The same PHP form contract rendered by Vue.
                        </p>
                    </header>
                    <Form
                        :errors="page.props.errors ?? {}"
                        :resource="form"
                        :theme="theme"
                    />
                </section>

                <section
                    class="min-w-0 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-5 shadow-sm"
                    data-slot="vue-resource-table"
                >
                    <header class="mb-5">
                        <h2 class="text-lg font-semibold text-(--inlay-text)">
                            Users
                        </h2>
                        <p class="mt-1 text-sm text-(--inlay-muted)">
                            The same PHP table, filters, actions, and pagination
                            rendered by Vue.
                        </p>
                    </header>
                    <Table :resource="table" :theme="theme" />
                </section>
            </div>
        </ResourcePage>
    </Panel>
</template>
