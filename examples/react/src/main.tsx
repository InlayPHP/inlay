import { Form } from '@inlayphp/forms-react'
import type { FormResource } from '@inlayphp/forms-react'
import { Table } from '@inlayphp/tables-react'
import type { TableResource } from '@inlayphp/tables-react'
import React from 'react'
import { createRoot } from 'react-dom/client'
import './app.css'

const base = { hidden: false, columnSpan: 1, extraAttributes: {}, default: null, placeholder: null, helperText: null, required: false, disabled: false, autofocus: false, readOnly: false, prefix: null, suffix: null, rules: [] }
const userForm: FormResource = { contract: 'inlay.forms.v1', type: 'form', name: 'create-user', action: null, method: 'post', columns: 2, submitLabel: 'Create user', data: {}, schema: [
  { ...base, type: 'text', name: 'name', label: 'Name', required: true, autofocus: true },
  { ...base, type: 'text', name: 'email', label: 'Email address', required: true, inputType: 'email' },
  { ...base, type: 'select', name: 'role', label: 'Role', options: [{ value: 'admin', label: 'Administrator' }, { value: 'member', label: 'Member' }] },
  { ...base, type: 'toggle', name: 'active', label: 'Active', default: true },
] }
const usersTable: TableResource = { contract: 'inlay.tables.v1', type: 'table', name: 'users', primaryKey: 'id', searchPlaceholder: 'Search users', columns: [
  { type: 'text-column', name: 'name', label: 'Name', sortable: true, searchable: true, toggleable: true, visible: true, alignment: 'left', tooltip: null, url: null, openUrlInNewTab: false },
  { type: 'badge-column', name: 'status', label: 'Status', sortable: false, searchable: false, toggleable: true, visible: true, alignment: 'left', tooltip: null, url: null, openUrlInNewTab: false, colors: { active: 'success', disabled: 'danger' } },
], filters: [{ type: 'select-filter', name: 'status', label: 'Status', default: null, options: [{ value: 'active', label: 'Active' }, { value: 'disabled', label: 'Disabled' }] }], actions: [{ name: 'edit', label: 'Edit', url: '/users/{id}/edit', method: 'get', color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }], headerActions: [], bulkActions: [], rows: [{ id: 1, name: 'Ada Lovelace', status: 'active' }, { id: 2, name: 'Grace Hopper', status: 'disabled' }], pagination: { currentPage: 1, lastPage: 1 }, selectable: false, deferFilters: true, query: null, emptyState: { heading: 'No users', description: 'Create your first user.' } }

function App() { return <main className="mx-auto grid max-w-6xl gap-12 p-4 sm:p-8"><section><h1 className="mb-6 text-2xl font-semibold tracking-tight">Create user</h1><Form onSubmit={console.log} resource={userForm} /></section><section><h2 className="mb-6 text-xl font-semibold">Users</h2><Table onAction={console.log} onQueryChange={console.log} resource={usersTable} /></section></main> }
createRoot(document.getElementById('root')!).render(<React.StrictMode><App /></React.StrictMode>)
