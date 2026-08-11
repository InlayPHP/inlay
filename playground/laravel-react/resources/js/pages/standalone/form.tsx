import { Head, usePage } from '@inertiajs/react';
import { createRendererRegistries } from '@inlayphp/core';
import { Form } from '@inlayphp/forms-react';
import type {
    FormErrors,
    FormRendererRegistryTypes,
    FormResource,
    SchemaComponentRenderer,
} from '@inlayphp/forms-react';
import { CheckCircle2, CircleHelp, ShieldCheck, UserRound } from 'lucide-react';
import { CodeDisclosure } from '@/components/code-disclosure';
import StandaloneLayout from '@/layouts/standalone-layout';

type PageProps = {
    errors: FormErrors;
    flash: { success: string | null };
    form: FormResource;
};

const schemaIcons = {
    'check-circle': CheckCircle2,
    'shield-check': ShieldCheck,
    user: UserRound,
} as const;

function SchemaIcon({ name }: { name: string }) {
    const Icon = schemaIcons[name as keyof typeof schemaIcons] ?? CircleHelp;

    return <Icon aria-hidden="true" className="size-[1em]" />;
}

const ReleaseSummary: SchemaComponentRenderer = ({
    component,
    renderSchema,
}) => (
    <aside className="rounded-xl border border-emerald-700/15 bg-emerald-50/70 p-4 dark:border-emerald-400/20 dark:bg-emerald-400/10">
        <p className="text-xs font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-300">
            {String(component.data?.eyebrow)}
        </p>
        <h2 className="mt-1 text-base font-semibold text-(--inlay-text)">
            {String(component.data?.title)}
        </h2>
        <div className="mt-3">{renderSchema()}</div>
    </aside>
);

const formRegistries = createRendererRegistries<FormRendererRegistryTypes>();
formRegistries.schema.register('demo/release-summary', ReleaseSummary, {
    owner: 'playground/demo',
});

const example = `// routes/web.php — one route, no Panel or Resource
Route::inlayForm('/standalone/forms', CreateStandaloneUser::class)
    ->middleware('auth')
    ->name('standalone.forms');

// app/Inlay/Forms/CreateStandaloneUser.php
use Illuminate\\Http\\Request;
use Illuminate\\Support\\HtmlString;
use Illuminate\\Support\\Str;
use Inlay\\Forms\\Support\\Set;

final class CreateStandaloneUser extends FormPage
{
    protected static string $component = 'standalone/form';

    protected function form(Form $form): Form
    {
        return $form
            ->validation(UserRules::class, operation: 'create')
            ->precognitive()
            ->schema([
                Callout::make('standalone-ready')
                    ->color(fn (string $operation): string =>
                        $operation === 'create' ? 'success' : 'info')
                    ->icon(fn (string $operation): string =>
                        $operation === 'create' ? 'check-circle' : 'information-circle')
                    ->description(fn (string $operation): string =>
                        "Rendered for the {$operation} operation.")
                    ->iconSize('large')
                    ->schema([
                        Text::make('The same schema works in Forms and Infolists.')
                            ->badge()
                            ->icon('check-circle')
                            ->fontFamily('mono')
                            ->size('small')
                            ->tooltip('Rendered from the shared PHP schema'),
                        Text::make('Choose an account type below.')
                            ->reactive(ContentExpression::state(
                                'account_type',
                                'Choose an account type below.',
                            )->prefix('Selected account type: '))
                            ->copyable()
                            ->copyMessage('Account type copied')
                            ->copyMessageDuration(5000),
                        Text::make(new HtmlString(
                            '<strong>Safe HTML:</strong> links and emphasis are sanitized.',
                        )),
                    ])
                    ->footerActions([
                        Action::make('browse-users')->url('/admin/users'),
                    ]),

                View::make('demo/release-summary')
                    ->viewData(fn (Request $request): array => [
                        'eyebrow' => 'Community schema view',
                        'title' => 'One PHP contract, either frontend',
                        'loadedFor' => $request->user()?->email,
                    ])
                    ->lazy()
                    ->loadingMessage('Loading the PHP view data…')
                    ->schema([
                        Text::make('Nested Inlay schema content.'),
                    ]),

                Tabs::make('account-details')
                    ->id('standalone-user-tabs')
                    ->persistTab()
                    ->persistTabInQueryString('form-tab')
                    ->tabs([
                        Tab::make('identity')->columns(2)->schema([
                            Grid::make(2)
                                ->dense()
                                ->columnSpanFull()
                                ->schema([
                                    TextInput::make('name')
                                        ->required()
                                        ->debounce(300)
                                        ->afterStateUpdated(
                                            fn (string $state, Set $set) =>
                                                $set('slug', Str::slug($state)),
                                        ),
                                    TextInput::make('slug')
                                        ->label('Generated slug')
                                        ->readOnly()
                                        ->dehydrated(false),
                                    TextInput::make('email')->email()->required(),
                                    Textarea::make('validation_notes')
                                        ->rows(2)
                                        ->autosize()
                                        ->minLength(10)
                                        ->dehydrated(false),
                                ]),
                            FileUpload::make('avatar')->image()->maxSize(2048),
                            RichEditor::make('notes')->columnSpan('full'),
                        ]),
                    ]),
            ]);
    }

    protected function submit(array $data, Request $request): RedirectResponse
    {
        User::create($data);

        return back()->with('success', 'User created.');
    }
}

// standalone/form.tsx — normally exported by a community adapter package
const ReleaseSummary = ({ component, renderSchema }) => (
    <article>
        <strong>{String(component.data?.title)}</strong>
        {renderSchema()}
    </article>
);

const formRegistries = createRendererRegistries();
formRegistries.schema.register('demo/release-summary', ReleaseSummary, {
    owner: '@acme/inlay-demo-react',
});

<Form
    resource={form}
    errors={errors}
    icons={{ '*': SchemaIcon }}
    registries={formRegistries}
/>`;

export default function StandaloneForm({ form, flash }: PageProps) {
    const { errors } = usePage<PageProps>().props;

    return (
        <StandaloneLayout
            description="Build and submit a schema-driven Inertia form from an ordinary Laravel controller. The form package does not require an Inlay Panel or Resource."
            eyebrow="inlayphp/forms · standalone"
            title="Use an Inlay form on any page"
        >
            <Head title="Standalone form" />

            {flash.success ? (
                <div
                    className="mb-5 rounded-(--inlay-radius) border border-emerald-700/15 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-300"
                    role="status"
                >
                    {flash.success}
                </div>
            ) : null}

            <section className="rounded-2xl bg-(--inlay-surface) p-5 shadow-sm ring-1 ring-(--inlay-border) sm:p-8">
                <Form
                    errors={errors}
                    icons={{ '*': SchemaIcon }}
                    registries={formRegistries}
                    resource={form}
                />
                <CodeDisclosure code={example} />
            </section>
        </StandaloneLayout>
    );
}
