import { Head, Link, usePage } from '@inertiajs/react';
import { Form } from '@inlayphp/forms-react';
import type { FormErrors, FormResource } from '@inlayphp/forms-react';
import { Table } from '@inlayphp/tables-react';
import type { TableResource } from '@inlayphp/tables-react';
import AdminLayout from '@/layouts/admin-layout';

type PageProps = {
    form: FormResource;
    table: TableResource;
    flash: { success: string | null };
    errors: FormErrors;
};

const packageTheme = {
    accent: '#2563eb',
    radius: '0.625rem',
    controlHeight: '2.5rem',
};

const tableClassNames = {
    filtersPanel:
        'bg-blue-50/60 ring-blue-700/10 dark:bg-blue-950/20 dark:ring-blue-300/10',
    applyButton: 'shadow-sm',
};

const formExample = `// Laravel controller
Form::make('create-user')
    ->action('/admin/users')
    ->columns(2)
    ->validation(UserRules::class, operation: 'create')
    ->precognitive(mode: 'blur', debounce: 350)
    ->schema([
        TextInput::make('name')->required()->maxLength(255),
        TextInput::make('email')->email()->required(),
        Select::make('role')->options([
            'admin' => 'Admin',
            'member' => 'Member',
        ]),
        Select::make('account_type')
            ->options(['personal' => 'Personal', 'company' => 'Company'])
            ->live(),
        TextInput::make('company_name')
            ->visibleWhen('account_type', 'company')
            ->requiredWhen('account_type', 'company'),
        Toggle::make('active')->default(true),
    ]);

// React page
<Form
    resource={userForm}
    errors={errors}
    theme={{ accent: '#2563eb', radius: '0.625rem' }}
/>`;

const tableExample = `// Laravel controller
use Inlay\\Actions\\Action;

final class UserResource extends Resource
{
    protected static string $model = User::class;
    protected static bool $softDeletes = true;

    public static function table(Table $table): Table
    {
        return $table
    ->columns([
        TextColumn::make('name')->searchable()->sortable(),
        BadgeColumn::make('status'),
        BooleanColumn::make('active'),
    ])
    ->filters([
        SelectFilter::make('role')->options([...]),
        SelectFilter::make('status')->options([...]),
    ])
    ->actions([
        Action::make('edit')->url('/admin/users/{id}/edit')->method('get'),
    ]);
    }
}

// Inlay automatically adds the trashed filter plus Delete, Restore,
// and Force delete row/bulk actions with Laravel policy checks.

// React page
<Table
    resource={usersTable}
    theme={{
        accent: '#2563eb',
        radius: '0.625rem',
        controlHeight: '2.5rem',
    }}
    classNames={{
        filtersPanel: 'bg-blue-50/60 dark:bg-blue-950/20',
        applyButton: 'shadow-sm',
    }}
/>`;

export default function UsersIndex({ form, table, flash }: PageProps) {
    const { errors } = usePage<PageProps>().props;

    return (
        <AdminLayout>
            <Head title="Form + Table playground" />
            <div className="isolate overflow-x-hidden text-zinc-950 antialiased dark:text-white">
                <div className="mx-auto max-w-7xl space-y-8">
                    <header className="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                        <div>
                            <p className="font-mono text-sm font-semibold tracking-wide text-blue-700 uppercase dark:text-blue-400">
                                Laravel 12 · Inertia v3 · React 19
                            </p>
                            <h1 className="mt-2 text-4xl font-semibold tracking-tight text-balance">
                                Users
                            </h1>
                            <p className="mt-3 max-w-[52ch] text-zinc-600 dark:text-zinc-300">
                                A PHP-first resource with a form, table,
                                filters, actions, validation, and CRUD pages.
                            </p>
                        </div>
                        <Link
                            className="inline-flex rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700"
                            href="/admin/users/create"
                        >
                            Create user
                        </Link>
                    </header>

                    {flash.success ? (
                        <div
                            className="rounded-xl border border-emerald-700/15 bg-emerald-50 px-4 py-3 text-base font-medium text-emerald-800 sm:text-sm dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-300"
                            role="status"
                        >
                            {flash.success}
                        </div>
                    ) : null}

                    <section className="min-w-0 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-zinc-950/5 sm:p-8 dark:bg-zinc-900 dark:ring-white/10">
                        <div className="mb-6">
                            <p className="text-base font-medium text-blue-700 sm:text-sm dark:text-blue-400">
                                inlayphp/forms
                            </p>
                            <h2 className="mt-1 text-2xl font-semibold tracking-tight text-balance">
                                Create a user
                            </h2>
                            <p className="mt-1 max-w-[56ch] text-base text-pretty text-zinc-600 sm:text-sm dark:text-zinc-300">
                                Required fields, defaults, reactive conditions,
                                server validation, and Inertia submission.
                                Choose “Company” to reveal a conditionally
                                required company name.
                            </p>
                        </div>
                        <Form
                            errors={errors}
                            resource={form}
                            theme={packageTheme}
                        />
                        <CodeDisclosure code={formExample} />
                    </section>

                    <section className="min-w-0 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-zinc-950/5 sm:p-8 dark:bg-zinc-900 dark:ring-white/10">
                        <div className="mb-6">
                            <p className="text-base font-medium text-blue-700 sm:text-sm dark:text-blue-400">
                                inlayphp/tables
                            </p>
                            <h2 className="mt-1 text-2xl font-semibold tracking-tight text-balance">
                                Users
                            </h2>
                            <p className="mt-1 max-w-[56ch] text-base text-pretty text-zinc-600 sm:text-sm dark:text-zinc-300">
                                Server-side search, filters, sorting,
                                pagination, badges, conditional row actions,
                                bulk actions, and soft-delete recovery.
                            </p>
                        </div>
                        <Table
                            classNames={tableClassNames}
                            resource={table}
                            theme={packageTheme}
                        />
                        <CodeDisclosure code={tableExample} />
                    </section>
                </div>
            </div>
        </AdminLayout>
    );
}

function CodeDisclosure({ code }: { code: string }) {
    return (
        <details className="group mt-8 max-w-full min-w-0 border-t border-zinc-950/10 pt-5 dark:border-white/10">
            <summary className="cursor-pointer rounded-lg text-base font-medium text-zinc-700 outline-none marker:text-zinc-400 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-500 sm:text-sm dark:text-zinc-200">
                View builder and React code
            </summary>
            <div className="mt-4 max-w-full min-w-0 overflow-hidden rounded-xl bg-zinc-950 ring-1 ring-white/10 group-not-open:hidden">
                <div className="border-b border-white/10 px-4 py-2 font-mono text-sm text-zinc-400">
                    PHP builder and React adapter
                </div>
                <pre className="max-w-full overflow-x-auto p-4 font-mono text-sm [tab-size:2] text-zinc-100">
                    <code>{code}</code>
                </pre>
            </div>
        </details>
    );
}
