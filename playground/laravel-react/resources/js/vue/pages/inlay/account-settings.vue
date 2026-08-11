<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AccountSettingsPage } from '@inlayphp/panels-vue';
import { Panel } from '@inlayphp/panels-vue';
import type { PanelDirectoryEntry, PanelResource } from '@inlayphp/panels-vue';
import type { FormErrors, FormResource, FormTheme } from '@inlayphp/forms-vue';
import AdminPanelHeader from '@/vue/components/admin-panel-header.vue';

defineProps<{
    profileForm: FormResource;
    passwordForm: FormResource;
    errors?: FormErrors;
    flash?: { success?: string | null };
    theme?: FormTheme;
    inlayPanel: PanelResource;
    inlayPanels?: PanelDirectoryEntry[];
    inlayPage?: Record<string, unknown>;
}>();
</script>

<template>
    <Panel
        :condition-values="{ page: inlayPage }"
        :resource="inlayPanel"
        :on-navigate="(href) => router.visit(href)"
    >
        <template #header-end>
            <AdminPanelHeader
                :current-panel-id="inlayPanel.id"
                :panels="inlayPanels ?? []"
            />
        </template>
        <AccountSettingsPage
            :profile-form="profileForm"
            :password-form="passwordForm"
            :errors="errors"
            :flash="flash"
            :theme="theme"
        />
    </Panel>
</template>
