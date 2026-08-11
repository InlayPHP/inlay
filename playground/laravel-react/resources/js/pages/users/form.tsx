import { Head, router, usePage } from '@inertiajs/react';
import type { ActionResource } from '@inlayphp/actions';
import { Form } from '@inlayphp/forms-react';
import type { FormErrors, FormResource } from '@inlayphp/forms-react';
import { RelationManagers, ResourcePage } from '@inlayphp/resources-react';
import type {
    RelationManagerResource,
    ResourceBreadcrumb,
    ResourceSubNavigationItem,
} from '@inlayphp/resources-react';
import type { WidgetDashboardResource } from '@inlayphp/widgets-react';
import AdminLayout from '@/layouts/admin-layout';

type PageProps = {
    breadcrumbs?: ResourceBreadcrumb[];
    form: FormResource;
    footerWidgets?: WidgetDashboardResource | null;
    heading: string;
    headerActions?: ActionResource[];
    headerWidgets?: WidgetDashboardResource | null;
    record?: { id: number; name: string };
    relations?: RelationManagerResource[];
    errors: FormErrors;
    subheading?: string | null;
    subNavigation?: ResourceSubNavigationItem[];
};

export default function UserFormPage({
    breadcrumbs = [],
    footerWidgets = null,
    form,
    heading,
    headerActions = [],
    headerWidgets = null,
    record,
    relations = [],
    subheading = null,
    subNavigation = [],
}: PageProps) {
    const { errors } = usePage<PageProps>().props;

    return (
        <AdminLayout>
            <Head title={heading} />
            <ResourcePage
                breadcrumbs={breadcrumbs}
                className="mx-auto w-full max-w-6xl"
                footerWidgets={footerWidgets}
                heading={heading}
                headerActions={headerActions}
                headerWidgets={headerWidgets}
                subNavigation={subNavigation}
                subheading={subheading}
                widgetProps={{
                    theme: { accent: '#2563eb', radius: '0.625rem' },
                }}
            >
                <div className="grid gap-10">
                    <section className="max-w-3xl rounded-2xl bg-white p-6 text-zinc-950 shadow-sm ring-1 ring-zinc-950/5 sm:p-8 dark:bg-zinc-900 dark:text-white dark:ring-white/10">
                        <Form
                            errors={errors}
                            resource={form}
                            theme={{
                                accent: '#2563eb',
                                radius: '0.625rem',
                            }}
                        />
                    </section>
                    {record && relations.length > 0 ? (
                        <section className="rounded-2xl bg-white p-6 text-zinc-950 shadow-sm ring-1 ring-zinc-950/5 sm:p-8 dark:bg-zinc-900 dark:text-white dark:ring-white/10">
                            <RelationManagers
                                onChanged={() =>
                                    router.reload({ only: ['relations'] })
                                }
                                resources={relations}
                            />
                        </section>
                    ) : null}
                </div>
            </ResourcePage>
        </AdminLayout>
    );
}
