<script setup lang="ts">
import { MediaManagerPage } from '@inlayphp/media-manager-vue';
import type { MediaManagerPageProps } from '@inlayphp/media-manager-vue';
import InlayPanelLayout from '../../layouts/inlay-panel-layout.vue';

defineProps<MediaManagerPageProps>();
</script>

<template>
    <InlayPanelLayout>
        <MediaManagerPage v-bind="$props" />
    </InlayPanelLayout>
</template>
