import { Head, Link } from '@inertiajs/react';
import { Table } from '@inlayphp/tables-react';
import type { TableResource } from '@inlayphp/tables-react';
import AdminLayout from '@/layouts/admin-layout';

type PageProps = {
    table: TableResource;
    parentRecord: { id: number; name: string };
    flash: { success: string | null };
};

const packageTheme = {
    accent: '#2563eb',
    radius: '0.625rem',
    controlHeight: '2.5rem',
};

export default function UserNotesIndexPage({
    table,
    parentRecord,
    flash,
}: PageProps) {
    return (
        <AdminLayout>
            <Head title={`${parentRecord.name} notes`} />
            <div className="mx-auto grid max-w-6xl gap-8">
                <header className="grid gap-2">
                    <Link
                        className="text-sm font-medium text-zinc-500 transition-colors hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white"
                        href="/admin/users"
                    >
                        ← Users
                    </Link>
                    <h1 className="text-3xl font-semibold tracking-tight">
                        Notes for {parentRecord.name}
                    </h1>
                    <p className="text-sm text-zinc-500 dark:text-zinc-400">
                        A nested resource: every row, mutation, and URL below is
                        scoped to user #{parentRecord.id}.
                    </p>
                    <Link
                        className="w-fit rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-500"
                        href={`/admin/users/${parentRecord.id}/notes/create`}
                    >
                        New note
                    </Link>
                </header>
                {flash.success ? (
                    <p className="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 ring-1 ring-emerald-600/10 dark:bg-emerald-950/30 dark:text-emerald-300">
                        {flash.success}
                    </p>
                ) : null}
                <section className="rounded-2xl bg-white p-6 text-zinc-950 shadow-sm ring-1 ring-zinc-950/5 sm:p-8 dark:bg-zinc-900 dark:text-white dark:ring-white/10">
                    <Table resource={table} theme={packageTheme} />
                </section>
            </div>
        </AdminLayout>
    );
}
