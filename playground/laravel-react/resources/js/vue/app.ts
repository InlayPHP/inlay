import { createInertiaApp, router, usePage } from '@inertiajs/vue3';
import { mediaManagerPages } from '@inlayphp/media-manager-vue';
import { Panel } from '@inlayphp/panels-vue';
import type { PanelDirectoryEntry, PanelResource } from '@inlayphp/panels-vue';
import { permissionManagerPages } from '@inlayphp/permission-manager-vue';
import { twoFactorAuthenticationPages } from '@inlayphp/two-factor-authentication-vue';
import { createApp, defineComponent, h } from 'vue';
import type { Component, DefineComponent } from 'vue';
import AdminPanelHeader from '@/vue/components/admin-panel-header.vue';

type ThemeMode = 'light' | 'dark' | 'system';

const themeModes: ThemeMode[] = ['light', 'dark', 'system'];
const themeChangeEvent = 'inlay-theme-change';

function storedThemeMode(): ThemeMode {
    const stored = window.localStorage.getItem('inlay-theme');

    return themeModes.includes(stored as ThemeMode)
        ? (stored as ThemeMode)
        : 'system';
}

function applyTheme(mode: ThemeMode): void {
    const dark =
        mode === 'dark' ||
        (mode === 'system' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches);

    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.dataset.themeMode = mode;
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
}

// Keep the Vue bundle on the same persisted theme as the React bundle. The
// event is emitted by the shared playground ThemeSwitcher, while this initial
// call also works when a Vue page is opened directly.
applyTheme(storedThemeMode());
window.addEventListener('storage', () => applyTheme(storedThemeMode()));
window.addEventListener(themeChangeEvent, () => applyTheme(storedThemeMode()));
window
    .matchMedia('(prefers-color-scheme: dark)')
    .addEventListener('change', () => {
        if (storedThemeMode() === 'system') {
            applyTheme('system');
        }
    });

/**
 * The Vue entrypoint for the playground.
 *
 * It resolves a deliberately small set of pages: the point is to run the Vue
 * renderer packages against real server payloads, and the packages are what is
 * under test, not the playground's own page components.
 */
const pages = import.meta.glob('./pages/**/*.vue');

// Inertia's Vue adapter intentionally erases page-prop generics from its
// resolver contract. Keep that boundary explicit instead of allowing every
// page's required props to be compared against an empty `DefineComponent`.
const asInertiaComponent = (component: unknown): DefineComponent =>
    component as DefineComponent;

/** Keep package-owned pages inside the same panel shell as local pages. */
const panelize = (component: Component): Component =>
    defineComponent({
        inheritAttrs: false,
        setup(_, { attrs }) {
            // Vue's usePage is not a React hook; this file also participates in
            // the playground's shared ESLint config, which includes the React
            // hooks rule for the sibling entrypoint.
            // eslint-disable-next-line react-hooks/rules-of-hooks
            const page = usePage<{
                inlayPanel?: PanelResource | null;
                inlayPanels?: PanelDirectoryEntry[];
            }>();

            return () => {
                const panel = page.props.inlayPanel;

                if (!panel) {
                    return h(component, attrs);
                }

                return h(
                    Panel,
                    {
                        resource: panel,
                        onNavigate: (href: string) => router.visit(href),
                    },
                    {
                        'header-end': () =>
                            h(AdminPanelHeader, {
                                currentPanelId: panel.id,
                                panels: page.props.inlayPanels ?? [],
                            }),
                        default: () => h(component, attrs),
                    },
                );
            };
        },
    });

createInertiaApp({
    resolve: (name) => {
        if (name in twoFactorAuthenticationPages) {
            return asInertiaComponent(
                panelize(
                    twoFactorAuthenticationPages[
                        name as keyof typeof twoFactorAuthenticationPages
                    ],
                ),
            );
        }

        const page = pages[`./pages/${name}.vue`];

        if (page) {
            return page().then(asInertiaComponent);
        }

        const packagePage =
            mediaManagerPages[name as keyof typeof mediaManagerPages] ??
            permissionManagerPages[name as keyof typeof permissionManagerPages];

        if (!packagePage) {
            throw new Error(
                `The Vue playground has no page for [${name}]. Add resources/js/vue/pages/${name}.vue.`,
            );
        }

        return asInertiaComponent(panelize(packagePage));
    },
    setup: ({ el, App, props, plugin }) => {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
