import { Head, useForm } from '@inertiajs/react';
import type { PanelResource } from '@inlayphp/panels-react';
import type { CSSProperties, FormEvent } from 'react';

type LoginProps = { inlayPanel: PanelResource };

export default function Login({ inlayPanel }: LoginProps) {
    const form = useForm({
        email: 'test@example.com',
        password: 'password',
        remember: false,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`${inlayPanel.path}/login`, {
            onFinish: () => form.reset('password'),
        });
    }

    const theme = inlayPanel.theme;
    const darkTheme = inlayPanel.darkTheme ?? {};
    const style = {
        '--login-light-accent': theme.accent ?? '#4f46e5',
        '--login-light-background': theme.background ?? '#f6f7fb',
        '--login-light-surface': theme.surface ?? '#ffffff',
        '--login-light-text': theme.foreground ?? '#18181b',
        '--login-light-muted': theme.muted ?? '#71717a',
        '--login-light-border': theme.border ?? 'rgb(24 24 27 / 0.12)',
        '--login-dark-accent': darkTheme.accent ?? '#818cf8',
        '--login-dark-background': darkTheme.background ?? '#09090b',
        '--login-dark-surface': darkTheme.surface ?? '#18181b',
        '--login-dark-text': darkTheme.foreground ?? '#fafafa',
        '--login-dark-muted': darkTheme.muted ?? '#a1a1aa',
        '--login-dark-border': darkTheme.border ?? 'rgb(255 255 255 / 0.12)',
        '--login-radius': theme.radius ?? '0.75rem',
    } as CSSProperties;

    return (
        <>
            <Head title="Sign in" />
            <main
                className="grid min-h-dvh place-items-center bg-(--login-light-background) px-4 py-12 text-(--login-light-text) antialiased [--login-accent:var(--login-light-accent)] [--login-border:var(--login-light-border)] [--login-muted:var(--login-light-muted)] [--login-surface:var(--login-light-surface)] dark:bg-(--login-dark-background) dark:text-(--login-dark-text) dark:[--login-accent:var(--login-dark-accent)] dark:[--login-border:var(--login-dark-border)] dark:[--login-muted:var(--login-dark-muted)] dark:[--login-surface:var(--login-dark-surface)]"
                style={style}
            >
                <section className="w-full max-w-md rounded-(--login-radius) bg-(--login-surface) p-7 shadow-xl ring-1 shadow-black/5 ring-(--login-border) sm:p-9">
                    <div className="mb-8">
                        <p className="text-xs font-semibold tracking-widest text-(--login-accent) uppercase">
                            Inlay panel
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            {inlayPanel.brandName ?? 'Admin'}
                        </h1>
                        <p className="mt-2 text-sm text-(--login-muted)">
                            Sign in to manage the demo application.
                        </p>
                    </div>

                    <form className="space-y-5" onSubmit={submit}>
                        <Field error={form.errors.email} label="Email address">
                            <input
                                autoComplete="email"
                                autoFocus
                                className="w-full rounded-(--login-radius) border-0 bg-(--login-surface) px-3.5 py-3 text-sm ring-1 ring-(--login-border) outline-none focus:ring-2 focus:ring-(--login-accent)"
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                                type="email"
                                value={form.data.email}
                            />
                        </Field>
                        <Field error={form.errors.password} label="Password">
                            <input
                                autoComplete="current-password"
                                className="w-full rounded-(--login-radius) border-0 bg-(--login-surface) px-3.5 py-3 text-sm ring-1 ring-(--login-border) outline-none focus:ring-2 focus:ring-(--login-accent)"
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                                type="password"
                                value={form.data.password}
                            />
                        </Field>
                        <label className="flex items-center gap-2 text-sm text-(--login-muted)">
                            <input
                                checked={form.data.remember}
                                onChange={(event) =>
                                    form.setData(
                                        'remember',
                                        event.target.checked,
                                    )
                                }
                                type="checkbox"
                            />
                            Remember me
                        </label>
                        <button
                            className="w-full rounded-(--login-radius) bg-(--login-accent) px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:brightness-95 disabled:cursor-wait disabled:opacity-60"
                            disabled={form.processing}
                            type="submit"
                        >
                            {form.processing ? 'Signing in…' : 'Sign in'}
                        </button>
                    </form>

                    <details className="mt-7 border-t border-(--login-border) pt-5 text-sm">
                        <summary className="cursor-pointer font-medium">
                            Demo credentials
                        </summary>
                        <p className="mt-3 font-mono text-xs text-(--login-muted)">
                            test@example.com / password
                        </p>
                    </details>
                </section>
            </main>
        </>
    );
}

function Field({
    children,
    error,
    label,
}: {
    children: React.ReactNode;
    error?: string;
    label: string;
}) {
    return (
        <label className="block space-y-2 text-sm font-medium">
            <span>{label}</span>
            {children}
            {error ? <span className="block text-red-600">{error}</span> : null}
        </label>
    );
}
