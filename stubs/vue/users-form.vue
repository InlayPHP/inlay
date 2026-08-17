<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PanelResource } from '@inlayphp/panels-vue';
import { Form } from '@inlayphp/forms-vue';
import type { FormErrors, FormResource } from '@inlayphp/forms-vue';
import { ResourcePage } from '@inlayphp/resources-vue';
import type { ResourceBreadcrumb, ResourceSubNavigationItem } from '@inlayphp/resources-vue';
import InlayPanelLayout from '../../layouts/inlay-panel-layout.vue';

type PageProps = {
    inlayPanel: PanelResource;
    breadcrumbs?: ResourceBreadcrumb[];
    errors?: FormErrors;
    form: FormResource;
    heading: string;
    subheading?: string | null;
    subNavigation?: ResourceSubNavigationItem[];
};

const page = usePage<PageProps>();
defineProps<PageProps>();
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
            :subheading="subheading"
        >
            <section class="rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-5 sm:p-8">
                <Form :errors="page.props.errors ?? {}" :resource="form" :theme="theme" />
            </section>
        </ResourcePage>
    </InlayPanelLayout>
</template>
