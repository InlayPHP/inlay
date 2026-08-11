<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { PanelResource } from '@inlayphp/panels-vue';

const props = defineProps<{
    inlayPanel: PanelResource;
}>();

const form = useForm({
    email: 'test@example.com',
    password: 'password',
    remember: false,
});

function submit(): void {
    form.post(`${props.inlayPanel.path}/login`, {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in" />
    <main
        class="grid min-h-dvh place-items-center bg-(--login-light-background) px-4 py-12 text-(--login-light-text) antialiased [--login-accent:var(--login-light-accent)] [--login-border:var(--login-light-border)] [--login-muted:var(--login-light-muted)] [--login-surface:var(--login-light-surface)] dark:bg-(--login-dark-background) dark:text-(--login-dark-text) dark:[--login-accent:var(--login-dark-accent)] dark:[--login-border:var(--login-dark-border)] dark:[--login-muted:var(--login-dark-muted)] dark:[--login-surface:var(--login-dark-surface)]"
        :style="{
            '--login-light-accent': props.inlayPanel.theme.accent ?? '#4f46e5',
            '--login-light-background':
                props.inlayPanel.theme.background ?? '#f6f7fb',
            '--login-light-surface':
                props.inlayPanel.theme.surface ?? '#ffffff',
            '--login-light-text':
                props.inlayPanel.theme.foreground ?? '#18181b',
            '--login-light-muted': props.inlayPanel.theme.muted ?? '#71717a',
            '--login-light-border':
                props.inlayPanel.theme.border ?? 'rgb(24 24 27 / 0.12)',
            '--login-dark-accent':
                props.inlayPanel.darkTheme?.accent ?? '#818cf8',
            '--login-dark-background':
                props.inlayPanel.darkTheme?.background ?? '#09090b',
            '--login-dark-surface':
                props.inlayPanel.darkTheme?.surface ?? '#18181b',
            '--login-dark-text':
                props.inlayPanel.darkTheme?.foreground ?? '#fafafa',
            '--login-dark-muted':
                props.inlayPanel.darkTheme?.muted ?? '#a1a1aa',
            '--login-dark-border':
                props.inlayPanel.darkTheme?.border ?? 'rgb(255 255 255 / 0.12)',
            '--login-radius': props.inlayPanel.theme.radius ?? '0.75rem',
        }"
    >
        <section
            class="w-full max-w-md rounded-(--login-radius) bg-(--login-surface) p-7 shadow-xl ring-1 shadow-black/5 ring-(--login-border) sm:p-9"
        >
            <div class="mb-8">
                <p
                    class="text-xs font-semibold tracking-widest text-(--login-accent) uppercase"
                >
                    Inlay panel
                </p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight">
                    {{ props.inlayPanel.brandName ?? 'Admin' }}
                </h1>
                <p class="mt-2 text-sm text-(--login-muted)">
                    Sign in to manage the demo application.
                </p>
            </div>

            <form class="space-y-5" @submit.prevent="submit">
                <label class="block space-y-2 text-sm font-medium">
                    <span>Email address</span>
                    <input
                        v-model="form.email"
                        autofocus
                        autocomplete="email"
                        class="w-full rounded-(--login-radius) border-0 bg-(--login-surface) px-3.5 py-3 text-sm ring-1 ring-(--login-border) outline-none focus:ring-2 focus:ring-(--login-accent)"
                        type="email"
                    />
                    <span v-if="form.errors.email" class="block text-red-600">
                        {{ form.errors.email }}
                    </span>
                </label>

                <label class="block space-y-2 text-sm font-medium">
                    <span>Password</span>
                    <input
                        v-model="form.password"
                        autocomplete="current-password"
                        class="w-full rounded-(--login-radius) border-0 bg-(--login-surface) px-3.5 py-3 text-sm ring-1 ring-(--login-border) outline-none focus:ring-2 focus:ring-(--login-accent)"
                        type="password"
                    />
                    <span
                        v-if="form.errors.password"
                        class="block text-red-600"
                    >
                        {{ form.errors.password }}
                    </span>
                </label>

                <label
                    class="flex items-center gap-2 text-sm text-(--login-muted)"
                >
                    <input v-model="form.remember" type="checkbox" />
                    Remember me
                </label>

                <button
                    class="w-full rounded-(--login-radius) bg-(--login-accent) px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:brightness-95 disabled:cursor-wait disabled:opacity-60"
                    :disabled="form.processing"
                    type="submit"
                >
                    {{ form.processing ? 'Signing in…' : 'Sign in' }}
                </button>
            </form>

            <details class="mt-7 border-t border-(--login-border) pt-5 text-sm">
                <summary class="cursor-pointer font-medium">
                    Demo credentials
                </summary>
                <p class="mt-3 font-mono text-xs text-(--login-muted)">
                    test@example.com / password
                </p>
            </details>
        </section>
    </main>
</template>
