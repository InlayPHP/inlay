import { createInertiaApp, usePage } from '@inertiajs/react';
import { twoFactorAuthenticationPages } from '@inlayphp/two-factor-authentication-react';
import type { ComponentType } from 'react';
import AdminLayout from '@/layouts/admin-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

type InlayPageProps = {
    inlayPanel?: unknown;
};

createInertiaApp({
    resolve: async (name) => {
        if (name in twoFactorAuthenticationPages) {
            const TwoFactorPage = twoFactorAuthenticationPages[
                name as keyof typeof twoFactorAuthenticationPages
            ] as unknown as ComponentType<Record<string, unknown>>;

            // A panel-owned settings page belongs inside the same shell as the
            // rest of the admin surface. Fortify's standalone bridge uses the
            // same Inertia page name but deliberately omits `inlayPanel`, so it
            // must remain a standalone screen.
            if (name === 'inlay-two-factor/settings') {
                return (props: Record<string, unknown>) => {
                    const { inlayPanel } = usePage<InlayPageProps>().props;

                    return inlayPanel ? (
                        <AdminLayout>
                            <TwoFactorPage {...props} />
                        </AdminLayout>
                    ) : (
                        <TwoFactorPage {...props} />
                    );
                };
            }

            return TwoFactorPage;
        }

        const pages = import.meta.glob('./pages/**/*.tsx');
        const page = pages[`./pages/${name}.tsx`];

        if (page) {
            return (await page()) as never;
        }

        throw new Error(`Unknown Inertia page: ${name}`);
    },
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
});
