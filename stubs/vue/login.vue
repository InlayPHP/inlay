<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { PanelResource } from '@inlayphp/panels-vue';

const props = defineProps<{ inlayPanel: PanelResource }>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function token(value: unknown, fallback: string): string {
    return typeof value === 'string' ? value : fallback;
}

const style: Record<string, string> = {
    '--inlay-light-accent': token(props.inlayPanel.theme.accent, '#4f46e5'),
    '--inlay-light-background': token(props.inlayPanel.theme.background, '#f6f7fb'),
    '--inlay-light-surface': token(props.inlayPanel.theme.surface, '#ffffff'),
    '--inlay-light-foreground': token(props.inlayPanel.theme.foreground, '#18181b'),
    '--inlay-light-muted': token(props.inlayPanel.theme.muted, '#71717a'),
    '--inlay-light-border': token(props.inlayPanel.theme.border, 'rgb(24 24 27 / 0.12)'),
    '--inlay-dark-accent': token(props.inlayPanel.darkTheme?.accent, '#818cf8'),
    '--inlay-dark-background': token(props.inlayPanel.darkTheme?.background, '#09090b'),
    '--inlay-dark-surface': token(props.inlayPanel.darkTheme?.surface, '#18181b'),
    '--inlay-dark-foreground': token(props.inlayPanel.darkTheme?.foreground, '#fafafa'),
    '--inlay-dark-muted': token(props.inlayPanel.darkTheme?.muted, '#a1a1aa'),
    '--inlay-dark-border': token(props.inlayPanel.darkTheme?.border, 'rgb(255 255 255 / 0.12)'),
    '--inlay-radius': token(props.inlayPanel.theme.radius, '0.75rem'),
    '--inlay-font-family': token(props.inlayPanel.theme['font-family'], 'ui-sans-serif, system-ui, sans-serif'),
};

function submit(): void {
    form.post(`${props.inlayPanel.path}/login`, {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head :title="`Sign in to ${props.inlayPanel.brandName ?? 'Inlay'}`" />
    <main
        class="flex min-h-dvh items-center justify-center bg-(--inlay-light-background) p-6 font-[family-name:var(--inlay-font-family)] text-(--inlay-light-foreground) dark:bg-(--inlay-dark-background) dark:text-(--inlay-dark-foreground)"
        :style="style"
    >
        <section
            class="w-full max-w-md rounded-(--inlay-radius) border border-(--inlay-light-border) bg-(--inlay-light-surface) p-6 shadow-(--inlay-shadow) dark:border-(--inlay-dark-border) dark:bg-(--inlay-dark-surface) sm:p-8"
        >
            <p class="text-sm font-medium text-(--inlay-light-accent) dark:text-(--inlay-dark-accent)">
                {{ props.inlayPanel.brandName ?? 'Inlay' }}
            </p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">Sign in to your panel</h1>
            <p class="mt-2 text-sm text-(--inlay-light-muted) dark:text-(--inlay-dark-muted)">
                Use your Laravel account to continue.
            </p>

            <form class="mt-8 space-y-5" @submit.prevent="submit">
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Email address</span>
                    <input
                        v-model="form.email"
                        autofocus
                        autocomplete="email"
                        class="w-full rounded-(--inlay-radius) border border-(--inlay-light-border) bg-transparent px-3 py-2.5 text-sm outline-none transition focus:border-(--inlay-light-accent) focus:ring-2 focus:ring-(--inlay-light-accent)/20 dark:border-(--inlay-dark-border) dark:focus:border-(--inlay-dark-accent)"
                        type="email"
                    />
                    <span v-if="form.errors.email" class="text-sm text-(--inlay-light-accent)">{{ form.errors.email }}</span>
                </label>
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Password</span>
                    <input
                        v-model="form.password"
                        autocomplete="current-password"
                        class="w-full rounded-(--inlay-radius) border border-(--inlay-light-border) bg-transparent px-3 py-2.5 text-sm outline-none transition focus:border-(--inlay-light-accent) focus:ring-2 focus:ring-(--inlay-light-accent)/20 dark:border-(--inlay-dark-border) dark:focus:border-(--inlay-dark-accent)"
                        type="password"
                    />
                    <span v-if="form.errors.password" class="text-sm text-(--inlay-light-accent)">{{ form.errors.password }}</span>
                </label>
                <label class="flex items-center gap-3 text-sm text-(--inlay-light-muted) dark:text-(--inlay-dark-muted)">
                    <input v-model="form.remember" type="checkbox" />
                    Remember me
                </label>
                <button
                    class="inline-flex min-h-(--inlay-button-height) w-full items-center justify-center rounded-(--inlay-radius) bg-(--inlay-light-accent) px-4 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-wait disabled:opacity-60 dark:bg-(--inlay-dark-accent)"
                    :disabled="form.processing"
                    type="submit"
                >
                    {{ form.processing ? 'Signing in…' : 'Sign in' }}
                </button>
            </form>
        </section>
    </main>
</template>
