import { Head } from '@inertiajs/react'
import { Form } from '@inlayphp/forms-react'
import type { FormTheme, SchemaComponentRendererProps } from '@inlayphp/forms-react'
import { Table } from '@inlayphp/tables-react'
import type { Column, TableResource, TableTheme } from '@inlayphp/tables-react'
import { BackLink, Flash, PageShell, PrimaryLink, Surface } from './PageShell'
import { PermissionMatrix } from './PermissionMatrix'
import type { AccessAuditPageProps, FormPageProps, ListPageProps, PermissionManagerTheme, UserAccessFormPageProps, UserAccessListPageProps } from './types'

const tableDescription = {
  roles: 'Bundle permissions into roles that are easy to understand, assign, and audit.',
  permissions: 'Review the atomic abilities registered by your application and packages.',
}

export function RoleIndexPage(props: ListPageProps) {
  return <ListPage {...props} description={tableDescription.roles} eyebrow="Access control" />
}

export function PermissionIndexPage(props: ListPageProps) {
  return <ListPage {...props} description={tableDescription.permissions} eyebrow="Access control" />
}

function ListPage({ resource, table, flash, theme, className, classNames, tableClassNames, description, eyebrow }: ListPageProps & { description: string; eyebrow: string }) {
  const createUrl = resource.pages.create?.url
  return (
    <>
      <Head title={resource.pluralLabel} />
      <PageShell action={createUrl ? <PrimaryLink href={createUrl}>Create {resource.label.toLocaleLowerCase()}</PrimaryLink> : null} className={className} classNames={classNames} description={description} eyebrow={eyebrow} theme={theme} title={resource.pluralLabel}>
        <Flash className={classNames?.flash} message={flash?.success} />
        <Surface className={classNames?.surface}><Table className={classNames?.table} classNames={tableClassNames} resource={table} theme={tableTheme(theme)} /></Surface>
      </PageShell>
    </>
  )
}

export function RoleFormPage({ resource, form, record, errors = {}, abilities, theme, className, classNames }: FormPageProps) {
  const isEdit = Boolean(record)
  const title = `${isEdit ? 'Edit' : 'Create'} ${resource.label.toLocaleLowerCase()}`
  const matrixRenderer = (props: SchemaComponentRendererProps) => <PermissionMatrix {...props} abilities={abilities} />
  return (
    <>
      <Head title={title} />
      <PageShell className={className} classNames={classNames} description="Give the role a clear name, choose its guard, and grant only the capabilities it needs." eyebrow="Access control" theme={theme} title={title}>
        <Surface className={classNames?.surface}>
          <BackLink href={resource.pages.index?.url ?? `/${resource.slug}`}>{resource.pluralLabel}</BackLink>
          <div className="mt-7"><Form className={classNames?.form} errors={errors} renderers={{ 'checkbox-list': matrixRenderer }} resource={form} theme={formTheme(theme)} /></div>
        </Surface>
      </PageShell>
    </>
  )
}

export function PermissionFormPage({ resource, form, record, errors = {}, theme, className, classNames }: FormPageProps) {
  const isEdit = Boolean(record)
  const title = `${isEdit ? 'Edit' : 'Create'} ${resource.label.toLocaleLowerCase()}`
  return (
    <>
      <Head title={title} />
      <PageShell className={className} classNames={classNames} description="Permissions are atomic abilities. Prefer assigning them through roles for day-to-day administration." eyebrow="Access control" theme={theme} title={title}>
        <Surface className={classNames?.surface}>
          <BackLink href={resource.pages.index?.url ?? `/${resource.slug}`}>{resource.pluralLabel}</BackLink>
          <div className="mt-7"><Form className={classNames?.form} errors={errors} resource={form} theme={formTheme(theme)} /></div>
        </Surface>
      </PageShell>
    </>
  )
}

export function UserAccessIndexPage({ table, userAccess, flash, theme, className, classNames, tableClassNames }: UserAccessListPageProps) {
  return (
    <>
      <Head title={userAccess.label} />
      <PageShell className={className} classNames={classNames} description="Assign reusable roles to users without duplicating policy rules." eyebrow="Access control" theme={theme} title={userAccess.label}>
        <Flash className={classNames?.flash} message={flash?.success} />
        <Surface className={classNames?.surface}><Table className={classNames?.table} classNames={tableClassNames} resource={table} theme={tableTheme(theme)} /></Surface>
      </PageShell>
    </>
  )
}

export function UserAccessFormPage({ form, record, userAccess, errors = {}, theme, className, classNames }: UserAccessFormPageProps) {
  const title = `Roles for ${record.name}`
  return (
    <>
      <Head title={title} />
      <PageShell className={className} classNames={classNames} description={record.email ? `Manage role membership for ${record.email}.` : 'Manage this user’s role membership.'} eyebrow="Access control" maxWidth="narrow" theme={theme} title={title}>
        <Surface className={classNames?.surface}>
          <BackLink href={userAccess.baseUrl}>{userAccess.label}</BackLink>
          <div className="mt-7"><Form className={classNames?.form} errors={errors} resource={form} theme={formTheme(theme)} /></div>
        </Surface>
      </PageShell>
    </>
  )
}

export function AccessAuditPage({ audit, theme, className, classNames, tableClassNames }: AccessAuditPageProps) {
  const summary = [
    ['Registered', audit.summary.registered],
    ['Synced', audit.summary.synced],
    ['Missing', audit.summary.missing],
    ['Stale', audit.summary.stale],
  ] as const
  const abilitiesTable = auditAbilitiesTable(audit)
  const staleTable = auditStaleTable(audit)

  return (
    <>
      <Head title="Access audit" />
      <PageShell className={className} classNames={classNames} description={`Compare abilities declared in PHP with permissions stored for the ${audit.guard} guard and see which roles grant them.`} eyebrow="Access control" theme={theme} title="Access audit">
        <dl className={`mt-7 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 ${classNames?.auditSummary ?? ''}`}>
          {summary.map(([label, value]) => <div className="rounded-(--inlay-radius) bg-(--inlay-surface) p-4 shadow-(--inlay-shadow) ring-1 ring-(--inlay-border)" key={label}><dt className="text-sm text-(--inlay-muted)">{label}</dt><dd className="mt-1 text-2xl font-semibold">{value}</dd></div>)}
        </dl>
        <Surface className={classNames?.surface}>
          <Table className={`${classNames?.table ?? ''} ${classNames?.auditTable ?? ''}`} classNames={tableClassNames} resource={abilitiesTable} theme={tableTheme(theme)} />
        </Surface>
        {audit.stale.length ? <Surface className={classNames?.surface}>
          <h2 className="text-lg font-semibold">Stale permissions</h2>
          <p className="mt-1 text-sm text-(--inlay-muted)">Stored permissions that are not declared by the current application.</p>
          <Table className={`mt-4 ${classNames?.table ?? ''} ${classNames?.auditTable ?? ''}`} classNames={tableClassNames} resource={staleTable} theme={tableTheme(theme)} />
        </Surface> : null}
      </PageShell>
    </>
  )
}

function formTheme(theme?: PermissionManagerTheme): FormTheme | undefined {
  return theme
}

function tableTheme(theme?: PermissionManagerTheme): TableTheme | undefined {
  return theme
}

function auditAbilitiesTable(audit: AccessAuditPageProps['audit']): TableResource {
  return staticTable('access-audit-abilities', 'name', [
    auditColumn('label', 'Ability'),
    auditColumn('name', 'Key'),
    auditColumn('owner', 'Owner'),
    { ...auditColumn('status', 'Status'), type: 'badge-column', colors: { synced: 'success', missing: 'danger' }, labels: { synced: 'Synced', missing: 'Missing' } },
    auditColumn('rolesLabel', 'Roles'),
  ], audit.abilities.map((ability) => ({
    ...ability,
    status: ability.synced ? 'synced' : 'missing',
    rolesLabel: ability.roles.length ? ability.roles.join(', ') : 'No roles',
  })), 'No registered abilities', 'Register abilities in your application to audit their synchronization.')
}

function auditStaleTable(audit: AccessAuditPageProps['audit']): TableResource {
  return staticTable('access-audit-stale', 'name', [
    auditColumn('name', 'Permission'),
    auditColumn('rolesLabel', 'Roles'),
  ], audit.stale.map((permission) => ({
    ...permission,
    rolesLabel: permission.roles.length ? permission.roles.join(', ') : 'No roles',
  })), 'No stale permissions', null)
}

function staticTable(name: string, primaryKey: string, columns: Column[], rows: TableResource['rows'], heading: string, description: string | null): TableResource {
  return {
    contract: 'inlay.tables.v1',
    type: 'table',
    name,
    primaryKey,
    searchPlaceholder: '',
    columns,
    filters: [],
    actions: [],
    headerActions: [],
    bulkActions: [],
    rows,
    pagination: null,
    selectable: false,
    deferFilters: false,
    query: null,
    emptyState: { heading, description },
  }
}

function auditColumn(name: string, label: string): Column {
  return {
    type: 'text-column',
    name,
    label,
    sortable: false,
    searchable: false,
    toggleable: false,
    visible: true,
    alignment: 'left',
    tooltip: null,
    url: null,
    openUrlInNewTab: false,
    wrap: true,
  }
}
