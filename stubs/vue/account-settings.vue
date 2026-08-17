<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { AccountSettingsPage } from '@inlayphp/panels-vue';
import type { PanelResource } from '@inlayphp/panels-vue';
import type { FormResource } from '@inlayphp/forms-vue';
import InlayPanelLayout from '../../layouts/inlay-panel-layout.vue';

type PageProps = {
    inlayPanel: PanelResource;
    profileForm: FormResource;
    passwordForm: FormResource;
    errors?: Record<string, string | string[]>;
    flash?: { success?: string | null };
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
        <Head title="Account settings" />
        <AccountSettingsPage
            :profile-form="profileForm"
            :password-form="passwordForm"
            :errors="page.props.errors"
            :flash="page.props.flash"
            :theme="theme"
        />
    </InlayPanelLayout>
</template>
