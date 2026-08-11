import type { FormTheme } from '@inlayphp/forms-vue'
import type { Column, TableResource, TableTheme } from '@inlayphp/tables-vue'
import type { AccessAuditPageProps, PermissionManagerTheme } from './types'

/**
 * The pure parts of the permission pages: theme narrowing, and the two static
 * tables the audit screen builds from its own payload.
 *
 * Kept out of the components so both renderers compute the same rows from the
 * same PHP payload rather than each shaping it their own way.
 */
export const tableDescription = {
  roles: 'Bundle permissions into roles that are easy to understand, assign, and audit.',
  permissions: 'Review the atomic abilities registered by your application and packages.',
}

export function formTheme(theme?: PermissionManagerTheme): FormTheme | undefined {
  return theme
}

export function tableTheme(theme?: PermissionManagerTheme): TableTheme | undefined {
  return theme
}

export function auditAbilitiesTable(audit: AccessAuditPageProps['audit']): TableResource {
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

export function auditStaleTable(audit: AccessAuditPageProps['audit']): TableResource {
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
