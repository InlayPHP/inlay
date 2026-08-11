import { AccessAuditPage, PermissionFormPage, PermissionIndexPage, RoleFormPage, RoleIndexPage, UserAccessFormPage, UserAccessIndexPage } from './pages'

export { PermissionMatrix } from './PermissionMatrix'
export type { PermissionMatrixProps } from './PermissionMatrix'
export { AccessAuditPage, PermissionFormPage, PermissionIndexPage, RoleFormPage, RoleIndexPage, UserAccessFormPage, UserAccessIndexPage }
export type { AccessAuditAbility, AccessAuditPageProps, AccessAuditProps, AbilityDefinition, FormPageProps, ListPageProps, PermissionManagerClassNames, PermissionManagerPageCustomization, PermissionManagerTheme, ResourceMetadata, ResourcePage, UserAccessFormPageProps, UserAccessListPageProps } from './types'

export const permissionManagerPages = {
  'inlay-permission-manager/roles/index': RoleIndexPage,
  'inlay-permission-manager/roles/form': RoleFormPage,
  'inlay-permission-manager/permissions/index': PermissionIndexPage,
  'inlay-permission-manager/permissions/form': PermissionFormPage,
  'inlay-permission-manager/users/index': UserAccessIndexPage,
  'inlay-permission-manager/users/form': UserAccessFormPage,
  'inlay-permission-manager/audit/index': AccessAuditPage,
} as const

export type PermissionManagerPageName = keyof typeof permissionManagerPages

export function resolvePermissionManagerPage(name: string) {
  return permissionManagerPages[name as PermissionManagerPageName]
}
