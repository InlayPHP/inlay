<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Form } from '@inlayphp/forms-vue';
import type { FormErrors, FormResource } from '@inlayphp/forms-vue';
import { ResourcePage } from '@inlayphp/resources-vue';
import type { ResourceBreadcrumb, ResourceSubNavigationItem } from '@inlayphp/resources-vue';
import InlayPanelLayout from '../../layouts/inlay-panel-layout.vue';

type PageProps = {
    breadcrumbs?: ResourceBreadcrumb[];
    errors?: FormErrors;
    form: FormResource;
    heading: string;
    subheading?: string | null;
    subNavigation?: ResourceSubNavigationItem[];
};

const page = usePage<PageProps>();
defineProps<PageProps>();
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
                <Form :errors="page.props.errors ?? {}" :resource="form" />
            </section>
        </ResourcePage>
    </InlayPanelLayout>
</template>
