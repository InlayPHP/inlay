import { Head, Link, usePage } from '@inertiajs/react';
import { Form } from '@inlayphp/forms-react';
import type { FormErrors, FormResource } from '@inlayphp/forms-react';
import AdminLayout from '@/layouts/admin-layout';

type PageProps = {
    form: FormResource;
    parentRecord: { id: number; name: string };
    record?: { id: number; title: string };
    errors: FormErrors;
};

export default function UserNoteFormPage({
    form,
    parentRecord,
    record,
}: PageProps) {
    const { errors } = usePage<PageProps>().props;

    return (
        <AdminLayout>
            <Head title={record ? `Edit ${record.title}` : 'Create note'} />
            <div className="mx-auto max-w-3xl rounded-2xl bg-white p-6 text-zinc-950 shadow-sm ring-1 ring-zinc-950/5 sm:p-8 dark:bg-zinc-900 dark:text-white dark:ring-white/10">
                <Link
                    className="text-sm font-medium text-zinc-500 transition-colors hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white"
                    href={`/admin/users/${parentRecord.id}/notes`}
                >
                    ← {parentRecord.name} notes
                </Link>
                <h1 className="mt-5 text-3xl font-semibold tracking-tight">
                    {record ? `Edit ${record.title}` : 'Create note'}
                </h1>
                <div className="mt-8">
                    <Form
                        errors={errors}
                        resource={form}
                        theme={{ accent: '#2563eb', radius: '0.625rem' }}
                    />
                </div>
            </div>
        </AdminLayout>
    );
}
