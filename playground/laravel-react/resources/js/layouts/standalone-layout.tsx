import { Link, usePage } from '@inertiajs/react';
import { Notifications } from '@inlayphp/notifications-react';
import { ArrowUpRight, Braces, TableProperties } from 'lucide-react';
import type { CSSProperties, PropsWithChildren } from 'react';
import { ThemeSwitcher } from '@/components/theme-switcher';

type StandaloneLayoutProps = PropsWithChildren<{
    description: string;
    eyebrow: string;
    title: string;
}>;

const navigation = [
    { href: '/standalone/forms', label: 'Form', icon: Braces },
    { href: '/standalone/tables', label: 'Table', icon: TableProperties },
];

// Form/Table roots intentionally own their token scope so they can be used
// without a Panel. These aliases let that scope inherit the standalone shell's
// light/dark tokens instead of falling back to a white surface in dark mode.
const standaloneThemeAliases = {
    '--inlay-default-accent': 'var(--inlay-accent)',
    '--inlay-default-surface': 'var(--inlay-surface)',
    '--inlay-default-surface-muted': 'var(--inlay-surface-muted)',
    '--inlay-default-foreground': 'var(--inlay-foreground)',
    '--inlay-default-muted': 'var(--inlay-muted)',
    '--inlay-default-border': 'var(--inlay-border)',
    '--inlay-default-danger': 'var(--inlay-danger)',
    '--inlay-panel-accent': 'var(--inlay-accent)',
    '--inlay-panel-accent-foreground': 'var(--inlay-accent-foreground)',
    '--inlay-panel-background': 'var(--inlay-background)',
    '--inlay-panel-surface': 'var(--inlay-surface)',
    '--inlay-panel-text': 'var(--inlay-foreground)',
    '--inlay-panel-muted': 'var(--inlay-muted)',
    '--inlay-panel-border': 'var(--inlay-border)',
    '--inlay-panel-control-border': 'var(--inlay-control-border)',
    '--inlay-panel-hover': 'var(--inlay-hover)',
    '--inlay-panel-radius': 'var(--inlay-radius)',
    '--inlay-panel-control-height': 'var(--inlay-control-height)',
    '--inlay-panel-button-height': 'var(--inlay-control-height)',
    '--inlay-panel-button-xs-height': '2rem',
    '--inlay-panel-button-sm-height': '2.25rem',
    '--inlay-panel-button-lg-height': '2.75rem',
    '--inlay-panel-icon-button-size': 'var(--inlay-control-height)',
} as CSSProperties;

export default function StandaloneLayout({
    children,
    description,
    eyebrow,
    title,
}: StandaloneLayoutProps) {
    const page = usePage<{
        inlayNotifications?: unknown;
    }>();
    const { inlayNotifications } = page.props;
    const url = page.url ?? '';

    return (
        <div
            className="min-h-dvh min-w-0 overflow-x-hidden bg-(--inlay-background) text-(--inlay-foreground) [--inlay-accent-foreground:#fff] [--inlay-accent:#4f46e5] [--inlay-background:#f6f7fb] [--inlay-border:rgb(24_24_27/0.12)] [--inlay-control-border:#d4d4d8] [--inlay-control-height:2.5rem] [--inlay-danger:#dc2626] [--inlay-foreground:#18181b] [--inlay-hover:#f4f4f5] [--inlay-muted:#71717a] [--inlay-radius:0.75rem] [--inlay-surface-muted:#f4f4f5] [--inlay-surface:#fff] dark:[--inlay-accent-foreground:#111827] dark:[--inlay-accent:#818cf8] dark:[--inlay-background:#09090b] dark:[--inlay-border:rgb(255_255_255/0.12)] dark:[--inlay-control-border:rgb(255_255_255/0.2)] dark:[--inlay-foreground:#fafafa] dark:[--inlay-hover:#27272a] dark:[--inlay-muted:#a1a1aa] dark:[--inlay-surface-muted:#242427] dark:[--inlay-surface:#18181b]"
            style={standaloneThemeAliases}
        >
            <header className="border-b border-(--inlay-border) bg-(--inlay-surface)">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
                    <Link
                        className="mr-auto inline-flex items-center gap-2.5 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-(--inlay-accent)"
                        href="/standalone/forms"
                    >
                        <span className="grid size-8 place-items-center rounded-lg bg-(--inlay-accent) text-sm font-bold text-(--inlay-accent-foreground)">
                            I
                        </span>
                        <span>
                            <span className="block text-sm leading-4 font-semibold">
                                InlayPHP
                            </span>
                            <span className="block text-xs text-(--inlay-muted)">
                                Standalone demos
                            </span>
                        </span>
                    </Link>

                    <nav
                        aria-label="Standalone package demos"
                        className="order-3 flex w-full gap-1 sm:order-2 sm:w-auto"
                    >
                        {navigation.map((item) => {
                            const active = url.startsWith(item.href);
                            const Icon = item.icon;

                            return (
                                <Link
                                    aria-current={active ? 'page' : undefined}
                                    className="inline-flex min-h-9 flex-1 items-center justify-center gap-2 rounded-(--inlay-radius) px-3 text-sm font-medium text-(--inlay-muted) transition hover:bg-(--inlay-hover) hover:text-(--inlay-foreground) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent) aria-current:bg-(--inlay-surface-muted) aria-current:text-(--inlay-foreground) sm:flex-none"
                                    href={item.href}
                                    key={item.href}
                                >
                                    <Icon
                                        aria-hidden="true"
                                        className="size-4"
                                        strokeWidth={1.8}
                                    />
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="order-2 flex items-center gap-1 sm:order-3">
                        <ThemeSwitcher />
                        <Link
                            className="inline-flex min-h-9 items-center gap-1.5 rounded-(--inlay-radius) px-3 text-sm font-medium text-(--inlay-muted) transition hover:bg-(--inlay-hover) hover:text-(--inlay-foreground) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)"
                            href="/admin"
                        >
                            Panel
                            <ArrowUpRight
                                aria-hidden="true"
                                className="size-3.5"
                                strokeWidth={1.8}
                            />
                        </Link>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-7xl px-4 py-10 sm:px-6 sm:py-14 lg:px-8">
                <header className="max-w-3xl">
                    <p className="font-mono text-xs font-semibold tracking-wider text-(--inlay-accent) uppercase">
                        {eyebrow}
                    </p>
                    <h1 className="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
                        {title}
                    </h1>
                    <p className="mt-3 max-w-2xl text-base leading-7 text-pretty text-(--inlay-muted)">
                        {description}
                    </p>
                </header>

                <div className="mt-8">{children}</div>
            </main>
            <Notifications notifications={inlayNotifications} />
        </div>
    );
}
