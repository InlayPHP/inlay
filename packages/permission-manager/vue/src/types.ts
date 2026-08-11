import type { FormErrors, FormResource } from '@inlayphp/forms-vue'
import type { TableClassNames, TableResource } from '@inlayphp/tables-vue'
import type { ThemeSource } from '@inlayphp/theme'

export type ResourcePage = {
  name: string
  path: string
  url: string | null
  operation: string
  component: string
  middleware: string[]
}

export type ResourceMetadata = {
  contract: 'inlay.resources.v1'
  resource: string
  model: string
  slug: string
  label: string
  pluralLabel: string
  navigationIcon: string | null
  pages: Record<string, ResourcePage>
}

export type AbilityDefinition = {
  name: string
  label: string
  group?: string | null
  description?: string | null
  dangerous?: boolean
}

export type PermissionManagerTheme = ThemeSource

export type PermissionManagerClassNames = Partial<Record<
  'root' | 'header' | 'surface' | 'flash' | 'form' | 'table' | 'auditSummary' | 'auditTable',
  string
>>

export type PermissionManagerPageCustomization = {
  className?: string
  classNames?: PermissionManagerClassNames
  tableClassNames?: TableClassNames
  theme?: PermissionManagerTheme
}

export type ListPageProps = PermissionManagerPageCustomization & {
  resource: ResourceMetadata
  table: TableResource
  flash?: { success?: string | null }
}

export type FormPageProps = PermissionManagerPageCustomization & {
  resource: ResourceMetadata
  form: FormResource
  record?: Record<string, unknown>
  errors?: FormErrors
  abilities?: AbilityDefinition[]
}

export type UserAccessListPageProps = PermissionManagerPageCustomization & {
  table: TableResource
  userAccess: { baseUrl: string; label: string }
  flash?: { success?: string | null }
}

export type UserAccessFormPageProps = PermissionManagerPageCustomization & {
  form: FormResource
  record: { id?: string | number; name: string; email?: string }
  userAccess: { baseUrl: string; label: string }
  errors?: FormErrors
}

export type AccessAuditAbility = AbilityDefinition & {
  owner: string
  synced: boolean
  roles: string[]
  roleCount: number
}

export type AccessAuditProps = {
  contract: 'inlay.permission-manager.audit.v1'
  guard: string
  summary: { registered: number; synced: number; missing: number; stale: number }
  abilities: AccessAuditAbility[]
  stale: Array<{ name: string; roles: string[]; roleCount: number }>
}

export type AccessAuditPageProps = PermissionManagerPageCustomization & {
  audit: AccessAuditProps
}
