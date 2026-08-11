import { Head, usePage } from '@inertiajs/react';
import { WidgetDashboard } from '@inlayphp/widgets-react';
import type { WidgetDashboardResource } from '@inlayphp/widgets-react';
import { adminIcons } from '@/components/admin-icons';
import AdminLayout from '@/layouts/admin-layout';
import type { User } from '@/types';

export default function Dashboard() {
    const { auth, inlayWidgets } = usePage<{
        auth: { user: User };
        inlayWidgets: WidgetDashboardResource;
    }>().props;

    return (
        <AdminLayout>
            <Head title="Dashboard" />
            <div className="mx-auto max-w-[90rem] space-y-7">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-sm font-semibold text-(--inlay-accent)">
                            Overview
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
                            Welcome back, {auth.user.name}
                        </h1>
                        <p className="mt-2 max-w-2xl text-sm text-(--inlay-muted) sm:text-base">
                            Monitor your application and jump back into the work
                            that needs attention.
                        </p>
                    </div>
                    <p className="text-sm text-(--inlay-muted)">
                        {new Intl.DateTimeFormat(undefined, {
                            dateStyle: 'full',
                        }).format(new Date())}
                    </p>
                </header>

                <WidgetDashboard icons={adminIcons} resource={inlayWidgets} />

                <details className="group rounded-(--inlay-radius) bg-(--inlay-surface) shadow-(--inlay-shadow) ring-1 ring-(--inlay-border)">
                    <summary className="cursor-pointer list-none px-5 py-4 text-sm font-semibold marker:hidden">
                        PHP widget source{' '}
                        <span
                            aria-hidden="true"
                            className="float-right text-(--inlay-muted) transition group-open:rotate-180"
                        >
                            ⌄
                        </span>
                    </summary>
                    <pre className="overflow-x-auto border-t border-(--inlay-border) bg-(--inlay-surface-muted) p-5 text-xs leading-6 text-(--inlay-muted)">
                        <code>{`StatsOverviewWidget::make('overview')
    ->columns(3)
    ->stats([
        Stat::make('Total users', User::count())
            ->description('Registered accounts')
            ->icon('users')
            ->url('/admin/users'),
    ]);

ChartWidget::make('user-growth')
    ->label('User growth')
    ->chartType('bar')
    ->labels($labels)
    ->dataset('New users', $values);`}</code>
                    </pre>
                </details>
            </div>
        </AdminLayout>
    );
}
