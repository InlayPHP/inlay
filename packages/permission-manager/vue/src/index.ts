import AccessAuditPage from './AccessAuditPage.vue'
import PermissionFormPage from './PermissionFormPage.vue'
import PermissionIndexPage from './PermissionIndexPage.vue'
import RoleFormPage from './RoleFormPage.vue'
import RoleIndexPage from './RoleIndexPage.vue'
import UserAccessFormPage from './UserAccessFormPage.vue'
import UserAccessIndexPage from './UserAccessIndexPage.vue'

export { default as AccessAuditPage } from './AccessAuditPage.vue'
export { default as BackLink } from './BackLink.vue'
export { default as Flash } from './Flash.vue'
export { default as PageShell } from './PageShell.vue'
export { default as PermissionFormPage } from './PermissionFormPage.vue'
export { default as PermissionIndexPage } from './PermissionIndexPage.vue'
export { default as PermissionMatrix } from './PermissionMatrix.vue'
export { default as PrimaryLink } from './PrimaryLink.vue'
export { default as RoleFormPage } from './RoleFormPage.vue'
export { default as RoleIndexPage } from './RoleIndexPage.vue'
export { default as Surface } from './Surface.vue'
export { default as UserAccessFormPage } from './UserAccessFormPage.vue'
export { default as UserAccessIndexPage } from './UserAccessIndexPage.vue'
export { auditAbilitiesTable, auditStaleTable, formTheme, tableDescription, tableTheme } from './pages'
export type * from './types'

/**
 * The Inertia component names PHP publishes, mapped to the Vue components that
 * render them — the same set the React package resolves, so a panel does not
 * have to know which renderer it is serving.
 */
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
