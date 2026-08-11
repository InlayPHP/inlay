import { router, usePage } from '@inertiajs/react';
import { Notifications } from '@inlayphp/notifications-react';
import { Panel } from '@inlayphp/panels-react';
import type { PanelResource } from '@inlayphp/panels-react';
import { LogOut } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { adminIcons } from '@/components/admin-icons';
import { ThemeSwitcher } from '@/components/theme-switcher';
import type { User } from '@/types';

type AdminPageProps = {
    auth: { user: User };
    inlayPanel: PanelResource;
    inlayPage?: Record<string, unknown>;
    page?: Record<string, unknown>;
    resource?: Record<string, unknown>;
    inlayNotifications?: unknown;
};

export default function AdminLayout({ children }: PropsWithChildren) {
    const { auth, inlayPanel, inlayPage, inlayNotifications, page, resource } =
        usePage<AdminPageProps>().props;

    return (
        <>
            <Panel
                conditionValues={{ page: inlayPage ?? page, resource }}
                icons={adminIcons}
                onNavigate={(href) => router.visit(href)}
                resource={inlayPanel}
                slots={{
                    headerEnd: (
                        <div className="flex items-center gap-1 sm:gap-2">
                            <ThemeSwitcher />
                            <div className="hidden items-center gap-3 border-l border-(--inlay-border) pl-3 sm:flex">
                                <div className="flex size-9 items-center justify-center rounded-full bg-(--inlay-accent) text-sm font-semibold text-(--inlay-accent-foreground)">
                                    {auth.user.name.slice(0, 1).toUpperCase()}
                                </div>
                                <div className="hidden text-left lg:block">
                                    <p className="max-w-40 truncate text-sm font-medium">
                                        {auth.user.name}
                                    </p>
                                    <p className="max-w-40 truncate text-xs text-(--inlay-muted)">
                                        {auth.user.email}
                                    </p>
                                </div>
                            </div>
                            <button
                                aria-label="Sign out"
                                className="inline-flex size-9 items-center justify-center rounded-(--inlay-radius) text-(--inlay-muted) transition hover:bg-(--inlay-hover) hover:text-(--inlay-foreground) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)"
                                onClick={() => router.post('/admin/logout')}
                                type="button"
                            >
                                <LogOut
                                    aria-hidden="true"
                                    className="size-4"
                                    strokeWidth={1.8}
                                />
                            </button>
                        </div>
                    ),
                }}
            >
                {children}
            </Panel>
            <Notifications notifications={inlayNotifications} />
        </>
    );
}
